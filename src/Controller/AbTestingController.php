<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\DailyMetricRepository;
use App\Repository\ThumbnailChangeRepository;
use App\Repository\VideoMetaSnapshotRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/analytics/ab-testing', name: 'ab_testing')]
class AbTestingController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videoRepo,
        private readonly DailyMetricRepository $metricRepo,
        private readonly ThumbnailChangeRepository $thumbnailChangeRepo,
        private readonly VideoMetaSnapshotRepository $snapshotRepo,
    ) {}

    public function __invoke(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $videos  = $this->videoRepo->findForUser($user);
        $results = [];

        foreach ($videos as $video) {
            // ── Thumbnail A/B tests ──────────────────────────────────────────
            $thumbChanges = $this->thumbnailChangeRepo->findForVideo($video);
            foreach ($thumbChanges as $change) {
                $changeDate = $change->getAppliedAt();
                $before     = $this->metricRepo->getStatsForVideoWindow(
                    $video,
                    $changeDate->modify('-7 days'),
                    $changeDate->modify('-1 day')
                );
                $after = $this->metricRepo->getStatsForVideoWindow(
                    $video,
                    $changeDate,
                    $changeDate->modify('+6 days')
                );

                if ($before['views'] === 0 && $after['views'] === 0) continue;

                $results[] = [
                    'type'       => 'thumbnail',
                    'video'      => $video,
                    'changed_at' => $changeDate,
                    'label'      => 'Changement miniature',
                    'old_label'  => 'Ancienne miniature',
                    'new_label'  => 'Nouvelle miniature',
                    'old_url'    => $change->getOldUrl(),
                    'new_url'    => $change->getNewUrl(),
                    'before'     => $before,
                    'after'      => $after,
                    'ctr_delta'  => $this->delta($before['avg_ctr'], $after['avg_ctr']),
                    'views_delta'=> $this->delta($before['views'] / max(1, 7), $after['views'] / max(1, 7)),
                    'watch_delta'=> $this->delta($before['watch_time'] / max(1, 7), $after['watch_time'] / max(1, 7)),
                ];
            }

            // ── Title A/B tests ──────────────────────────────────────────────
            $snapshots = $this->snapshotRepo->findAllForVideo($video);
            for ($i = 1; $i < count($snapshots); $i++) {
                $prev = $snapshots[$i - 1];
                $curr = $snapshots[$i];
                if ($prev->getTitle() === $curr->getTitle()) continue;

                $changeDate = $curr->getRecordedAt();
                $before     = $this->metricRepo->getStatsForVideoWindow(
                    $video,
                    $changeDate->modify('-7 days'),
                    $changeDate->modify('-1 day')
                );
                $after = $this->metricRepo->getStatsForVideoWindow(
                    $video,
                    $changeDate,
                    $changeDate->modify('+6 days')
                );

                if ($before['views'] === 0 && $after['views'] === 0) continue;

                $results[] = [
                    'type'       => 'title',
                    'video'      => $video,
                    'changed_at' => $changeDate,
                    'label'      => 'Changement de titre',
                    'old_label'  => $prev->getTitle(),
                    'new_label'  => $curr->getTitle(),
                    'old_url'    => null,
                    'new_url'    => null,
                    'before'     => $before,
                    'after'      => $after,
                    'ctr_delta'  => $this->delta($before['avg_ctr'], $after['avg_ctr']),
                    'views_delta'=> $this->delta($before['views'] / max(1, 7), $after['views'] / max(1, 7)),
                    'watch_delta'=> $this->delta($before['watch_time'] / max(1, 7), $after['watch_time'] / max(1, 7)),
                ];
            }
        }

        // Most recent first
        usort($results, fn($a, $b) => $b['changed_at'] <=> $a['changed_at']);

        return $this->render('analytics/ab_testing.html.twig', [
            'results' => $results,
        ]);
    }

    private function delta(mixed $before, mixed $after): ?float
    {
        if ($before === null || $after === null) return null;
        $b = (float)$before;
        $a = (float)$after;
        if ($b === 0.0) return $a > 0 ? 100.0 : 0.0;
        return round(($a - $b) / $b * 100, 1);
    }
}
