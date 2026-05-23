<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ChannelStatsRepository;
use App\Repository\GoogleTokenRepository;
use App\Repository\VideoStatsRepository;
use App\Service\GoogleAuthService;
use App\Service\YouTubeDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly YouTubeDataService $youtubeService,
        private readonly ChannelStatsRepository $channelStatsRepo,
        private readonly VideoStatsRepository $videoStatsRepo,
        private readonly GoogleTokenRepository $tokenRepo,
    ) {}

    #[Route('/', name: 'dashboard')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $isConnected = $this->authService->isAuthenticatedUser($user);
        $latestStats = null;
        $topVideos = [];
        $chartData = ['labels' => [], 'views' => [], 'subscribers' => [], 'watchTime' => []];

        if ($isConnected) {
            $latestStats = $this->channelStatsRepo->findLatestForUser($user);

            if ($latestStats) {
                $topVideos = $this->videoStatsRepo->findMostRecentForUser($user);

                // Fetch live daily analytics from YouTube Analytics API
                $dailyRows = $this->youtubeService->getDailyAnalytics($user, 30);

                if (!empty($dailyRows)) {
                    foreach ($dailyRows as $row) {
                        $chartData['labels'][]      = (new \DateTimeImmutable($row[0]))->format('d/m');
                        $chartData['views'][]       = (int) ($row[1] ?? 0);
                        $chartData['watchTime'][]   = round((int)($row[2] ?? 0) / 60, 1); // minutes → heures
                        $chartData['subscribers'][] = (int) ($row[3] ?? 0);
                    }
                } else {
                    // Fallback : snapshots DB, dédupliqués par jour
                    $seen = [];
                    foreach ($this->channelStatsRepo->findDailyStatsForUser($user, 90) as $stat) {
                        $day = $stat->getRecordedAt()->format('Y-m-d');
                        if (isset($seen[$day])) continue;
                        $seen[$day] = true;
                        $chartData['labels'][]      = $stat->getRecordedAt()->format('d/m');
                        $chartData['views'][]       = $stat->getViewCount();
                        $chartData['subscribers'][] = $stat->getSubscriberCount();
                        $chartData['watchTime'][]   = round((int)($stat->getWatchTimeMinutes() ?? 0) / 60, 1);
                    }
                }
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'is_connected' => $isConnected,
            'latest_stats' => $latestStats,
            'top_videos' => array_slice($topVideos, 0, 10),
            'chart_data' => $chartData,
        ]);
    }

    #[Route('/sync', name: 'sync_data', methods: ['POST'])]
    public function sync(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        try {
            $result = $this->youtubeService->syncAll($user);
            $this->addFlash('success', sprintf(
                'Synchronisation réussie ! %d vidéos pour "%s".',
                $result['videos_synced'],
                $result['channel']
            ));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur: ' . $e->getMessage());
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/videos', name: 'videos')]
    public function videos(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->authService->isAuthenticatedUser($user)) {
            return $this->redirectToRoute('dashboard');
        }

        $videos = $this->videoStatsRepo->findMostRecentForUser($user);
        $sortBy = $request->query->get('sort', 'views');

        usort($videos, function ($a, $b) use ($sortBy) {
            return match($sortBy) {
                'likes'       => $b->getLikeCount() <=> $a->getLikeCount(),
                'comments'    => $b->getCommentCount() <=> $a->getCommentCount(),
                'date'        => $b->getPublishedAt() <=> $a->getPublishedAt(),
                'subscribers' => ($b->getSubscribersGained() ?? 0) <=> ($a->getSubscribersGained() ?? 0),
                default       => $b->getViewCount() <=> $a->getViewCount(),
            };
        });

        return $this->render('dashboard/videos.html.twig', [
            'videos' => $videos,
            'sort_by' => $sortBy,
        ]);
    }

    #[Route('/api/chart-data', name: 'api_chart_data')]
    public function chartData(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $days = min(365, max(1, (int) $request->query->get('days', 30)));
        $dailyStats = $this->channelStatsRepo->findDailyStatsForUser($user, $days);

        $data = ['labels' => [], 'views' => [], 'subscribers' => [], 'watchTime' => []];
        foreach ($dailyStats as $stat) {
            $data['labels'][]      = $stat->getRecordedAt()->format('d/m');
            $data['views'][]       = $stat->getViewCount();
            $data['subscribers'][] = $stat->getSubscriberCount();
            $data['watchTime'][]   = round((int)($stat->getWatchTimeMinutes() ?? 0) / 60, 1);
        }

        return new JsonResponse($data);
    }
}
