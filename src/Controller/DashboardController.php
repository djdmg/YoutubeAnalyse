<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\User;
use App\Message\RunAiAnalysisMessage;
use App\Message\SyncYouTubeMessage;
use App\Repository\ChannelStatsRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\GoalRepository;
use App\Repository\GoogleTokenRepository;
use App\Repository\VideoStatsRepository;
use App\Service\GoogleAuthService;
use App\Service\YouTubeDataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[IsGranted('ROLE_USER')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly YouTubeDataService $youtubeService,
        private readonly ChannelStatsRepository $channelStatsRepo,
        private readonly VideoStatsRepository $videoStatsRepo,
        private readonly GoogleTokenRepository $tokenRepo,
        private readonly GoalRepository $goalRepo,
        private readonly DailyMetricRepository $dailyMetricRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly CacheInterface $cache,
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

        // ── Goals ──────────────────────────────────────────────────────────────
        $goals = $this->goalRepo->findActiveForUser($user);

        if (!empty($goals) && $latestStats) {
            // Compute total views over last 30 days for the user
            $globalStats = $this->dailyMetricRepo->getGlobalStatsForUser($user, 30);
            $totalViews30 = (int) ($globalStats['total_views'] ?? 0);
            $totalWatchTime30 = (int) ($globalStats['total_watch_time'] ?? 0);
            $subscriberCount = $latestStats->getSubscriberCount();

            foreach ($goals as $goal) {
                $currentVal = match($goal->getType()) {
                    'subscribers' => $subscriberCount,
                    'views'       => $totalViews30,
                    'watch_time'  => $totalWatchTime30,
                    default       => $goal->getCurrentValue(),
                };
                $goal->setCurrentValue($currentVal);

                // Mark as achieved if reached
                if (!$goal->isAchieved() && $currentVal >= $goal->getTargetValue()) {
                    $goal->setIsAchieved(true);
                    $goal->setAchievedAt(new \DateTimeImmutable());
                }
            }
            $this->em->flush();
        }

        // Estimated revenue (total views 30d × RPM)
        $globalStats30 = ($isConnected && $latestStats)
            ? $this->dailyMetricRepo->getGlobalStatsForUser($user, 30)
            : null;
        $totalRevenue30 = $globalStats30
            ? round((int)($globalStats30['total_views'] ?? 0) * $user->getEstimatedRpm() / 1000, 2)
            : null;

        return $this->render('dashboard/index.html.twig', [
            'is_connected'   => $isConnected,
            'latest_stats'   => $latestStats,
            'top_videos'     => array_slice($topVideos, 0, 10),
            'chart_data'     => $chartData,
            'goals'          => $goals,
            'total_revenue'  => $totalRevenue30,
            'estimated_rpm'  => $user->getEstimatedRpm(),
        ]);
    }

    #[Route('/sync', name: 'sync_data', methods: ['POST'])]
    public function sync(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $jobId  = bin2hex(random_bytes(8));
        $cacheKey = 'job_' . $jobId;

        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(300);
            return ['status' => 'pending'];
        });

        $this->bus->dispatch(new SyncYouTubeMessage($user->getId(), $jobId));

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'jobId' => $jobId]);
        }

        $this->addFlash('info', 'Synchronisation lancée en arrière-plan.');
        return $this->redirectToRoute('dashboard');
    }

    #[Route('/sync-status/{jobId}', name: 'sync_status', methods: ['GET'])]
    public function syncStatus(string $jobId): JsonResponse
    {
        $result = $this->cache->get('job_' . $jobId, function (ItemInterface $item) {
            $item->expiresAfter(0);
            return null;
        });

        if ($result === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Job introuvable ou expiré.']);
        }

        return new JsonResponse($result);
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
