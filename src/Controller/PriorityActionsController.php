<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\ThumbnailChangeRepository;
use App\Repository\VideoMetaSnapshotRepository;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/analytics/priority-actions', name: 'priority_actions')]
class PriorityActionsController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videoRepo,
        private readonly DailyMetricRepository $metricRepo,
        private readonly AiReportRepository $aiReportRepo,
        private readonly ThumbnailChangeRepository $thumbnailChangeRepo,
        private readonly VideoMetaSnapshotRepository $snapshotRepo,
    ) {}

    public function __invoke(): Response
    {
        /** @var User $user */
        $user          = $this->getUser();
        $videos        = $this->videoRepo->findForUser($user);
        $priorityStats = $this->metricRepo->getPriorityStatsForUser($user);

        $actions = [];

        foreach ($videos as $video) {
            $vid   = $video->getId();
            $stats = $priorityStats[$vid] ?? [];
            $score = 0;
            $items = [];

            $ctr         = $stats['avg_ctr'] ?? null;
            $retention   = $stats['avg_retention'] ?? null;
            $impressions = $stats['total_impressions'] ?? 0;

            // ── CTR Opportunity ──────────────────────────────────────────────
            if ($ctr !== null && $ctr < 3.0 && $impressions > 3000) {
                $pts    = (int) round(min(35, (3.0 - $ctr) / 3.0 * 35));
                $score += $pts;
                $items[] = [
                    'type'    => 'ctr',
                    'icon'    => 'fa-mouse-pointer',
                    'color'   => '#f59e0b',
                    'label'   => sprintf('CTR faible : %.2f%% avec %s impressions', $ctr, number_format($impressions, 0, ',', ' ')),
                    'action'  => 'Tester un nouveau titre ou une nouvelle miniature',
                    'pts'     => $pts,
                ];
            }

            // ── Retention Issue ──────────────────────────────────────────────
            if ($retention !== null && $retention < 35.0) {
                $pts    = (int) round(min(25, (35.0 - $retention) / 35.0 * 25));
                $score += $pts;
                $items[] = [
                    'type'    => 'retention',
                    'icon'    => 'fa-chart-area',
                    'color'   => '#ef4444',
                    'label'   => sprintf('Rétention basse : %.1f%%', $retention),
                    'action'  => 'Analyser les points de décrochage et améliorer l\'accroche',
                    'pts'     => $pts,
                ];
            }

            // ── Stale Title ──────────────────────────────────────────────────
            $snapshots = $this->snapshotRepo->findAllForVideo($video);
            $lastSnap  = !empty($snapshots) ? end($snapshots) : null;
            $titleAge  = $lastSnap
                ? (int) (new \DateTimeImmutable())->diff($lastSnap->getRecordedAt())->days
                : ($video->getPublishedAt() ? (int) (new \DateTimeImmutable())->diff($video->getPublishedAt())->days : 999);

            if ($titleAge > 180) {
                $pts    = 15;
                $score += $pts;
                $items[] = [
                    'type'   => 'title',
                    'icon'   => 'fa-heading',
                    'color'  => '#8b5cf6',
                    'label'  => sprintf('Titre inchangé depuis %d jours', $titleAge),
                    'action' => 'Rafraîchir le titre avec de nouveaux mots-clés tendance',
                    'pts'    => $pts,
                ];
            }

            // ── No Thumbnail Change ──────────────────────────────────────────
            $thumbChanges = $this->thumbnailChangeRepo->findForVideo($video);
            $lastThumb    = !empty($thumbChanges) ? $thumbChanges[0]->getAppliedAt() : null;
            $thumbAge     = $lastThumb
                ? (int) (new \DateTimeImmutable())->diff($lastThumb)->days
                : 999;

            if ($thumbAge > 90) {
                $pts    = 15;
                $score += $pts;
                $items[] = [
                    'type'   => 'thumbnail',
                    'icon'   => 'fa-image',
                    'color'  => '#06b6d4',
                    'label'  => $lastThumb
                        ? sprintf('Miniature non modifiée depuis %d jours', $thumbAge)
                        : 'Miniature jamais testée',
                    'action' => 'Générer et tester une nouvelle miniature IA',
                    'pts'    => $pts,
                ];
            }

            // ── Negative Comment Sentiment ───────────────────────────────────
            $commentReport = $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, 168);
            if ($commentReport) {
                $payload = $commentReport->getPayload() ?? [];
                $sentimentGlobal = strtolower($payload['sentiment_global'] ?? '');
                $hasNegative = $sentimentGlobal === 'négatif' || ($payload['score_sentiment'] ?? 1) < 0.3;
                if ($hasNegative) {
                    $score  += 20;
                    $items[] = [
                        'type'   => 'sentiment',
                        'icon'   => 'fa-comments',
                        'color'  => '#ec4899',
                        'label'  => 'Commentaires à tendance négative détectés',
                        'action' => 'Répondre aux commentaires et améliorer la description',
                        'pts'    => 20,
                    ];
                }
            }

            // ── High Opportunity: good impressions but no analysis done ──────
            $hasNoAnalysis = !$this->aiReportRepo->findRecentDone($video, AiReportType::SeoOptimization, 720);
            if ($hasNoAnalysis && $impressions > 5000) {
                $pts    = 10;
                $score += $pts;
                $items[] = [
                    'type'   => 'seo',
                    'icon'   => 'fa-search',
                    'color'  => '#10b981',
                    'label'  => 'Aucune analyse SEO récente malgré un fort volume',
                    'action' => 'Lancer une analyse SEO complète',
                    'pts'    => $pts,
                ];
            }

            if ($score > 0) {
                $pub = $video->getPublishedAt();
                $actions[] = [
                    'video'      => $video,
                    'score'      => min(100, $score),
                    'items'      => $items,
                    'stats'      => $stats,
                    'title_age'  => $titleAge,
                    'thumb_age'  => $thumbAge,
                    'pub_days'   => $pub ? (int)(new \DateTimeImmutable())->diff($pub)->days : null,
                ];
            }
        }

        usort($actions, fn($a, $b) => $b['score'] <=> $a['score']);

        return $this->render('analytics/priority_actions.html.twig', [
            'actions'      => array_slice($actions, 0, 30),
            'total_videos' => count($videos),
            'total_alerts' => count($actions),
        ]);
    }
}
