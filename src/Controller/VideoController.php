<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\RetentionPointRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoMetaSnapshotRepository;
use App\Repository\VideoSearchTermRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/analytics')]
class VideoController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videoRepo,
        private readonly DailyMetricRepository $metricRepo,
        private readonly RetentionPointRepository $retentionRepo,
        private readonly CommentRepository $commentRepo,
        private readonly AiReportRepository $aiReportRepo,
        private readonly VideoMetaSnapshotRepository $snapshotRepo,
        private readonly VideoSearchTermRepository $searchTermRepo,
    ) {}

    #[Route('/videos', name: 'analytics_videos')]
    public function list(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $sortBy = $request->query->get('sort', 'views');
        $videos = $this->videoRepo->findForUser($user);

        $listStats = $this->metricRepo->getListStatsForUser($user);

        $videosData = [];
        foreach ($videos as $video) {
            $stats     = $listStats[$video->getId()] ?? ['total_views' => 0, 'avg_ctr' => null, 'total_watch_time' => 0];
            $anomaly   = $this->aiReportRepo->findRecentDone($video, AiReportType::Anomaly, 168);
            $sentiment = $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, 168);
            $prediction= $this->aiReportRepo->findRecentDone($video, AiReportType::Prediction, 720);

            $videosData[] = [
                'video'      => $video,
                'stats'      => $stats,
                'anomaly'    => $anomaly,
                'sentiment'  => $sentiment,
                'prediction' => $prediction,
            ];
        }

        usort($videosData, function ($a, $b) use ($sortBy) {
            $sa = $a['stats'];
            $sb = $b['stats'];
            return match($sortBy) {
                'ctr'        => ($sb['avg_ctr'] ?? 0) <=> ($sa['avg_ctr'] ?? 0),
                'watch_time' => ($sb['total_watch_time'] ?? 0) <=> ($sa['total_watch_time'] ?? 0),
                'date'       => ($b['video']->getPublishedAt() ?? new \DateTimeImmutable('1970-01-01')) <=> ($a['video']->getPublishedAt() ?? new \DateTimeImmutable('1970-01-01')),
                default      => ($sb['total_views'] ?? 0) <=> ($sa['total_views'] ?? 0),
            };
        });

        return $this->render('analytics/videos.html.twig', [
            'videos_data' => $videosData,
            'sort_by'     => $sortBy,
        ]);
    }

    #[Route('/videos/{youtubeId}', name: 'analytics_video_detail')]
    public function detail(string $youtubeId): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            throw $this->createNotFoundException('Vidéo introuvable.');
        }

        $metrics      = $this->metricRepo->findForVideo($video, 30);
        $retention    = $this->retentionRepo->findLatestForVideo($video);
        $comments     = $this->commentRepo->findNewForVideo($video);
        $titleReport  = $this->aiReportRepo->findRecentDone($video, AiReportType::TitleOptimization, 168);
        $commentReport= $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, 168);
        $anomalyReport= $this->aiReportRepo->findRecentDone($video, AiReportType::Anomaly, 168);
        $prediction   = $this->aiReportRepo->findRecentDone($video, AiReportType::Prediction, 720);
        $seoReport    = $this->aiReportRepo->findRecentDone($video, AiReportType::SeoOptimization, 168);
        $searchTerms  = $this->searchTermRepo->findTopForVideo($video, 20);
        $snapshots    = $this->snapshotRepo->findAllForVideo($video);
        $metaHistory  = $this->buildMetaHistory($snapshots, $metrics);

        $chartData = ['labels' => [], 'views' => [], 'ctr' => [], 'watchTime' => [], 'subscribers' => []];
        foreach ($metrics as $m) {
            $chartData['labels'][]      = $m->getDate()->format('d/m');
            $chartData['views'][]       = $m->getViews();
            $chartData['ctr'][]         = round($m->getCtr() ?? 0, 2);
            $chartData['watchTime'][]   = round(($m->getWatchTimeMinutes() ?? 0) / 60, 1);
            $chartData['subscribers'][] = $m->getSubscribersGained() ?? 0;
        }

        $retentionData = ['labels' => [], 'values' => []];
        foreach ($retention as $rp) {
            $retentionData['labels'][] = $rp->getSecond();
            $retentionData['values'][] = round($rp->getRetentionPercent(), 1);
        }

        return $this->render('analytics/video_detail.html.twig', [
            'video'          => $video,
            'metrics'        => $metrics,
            'latest_metric'  => !empty($metrics) ? end($metrics) : null,
            'chart_data'     => $chartData,
            'retention_data' => $retentionData,
            'comments'       => $comments,
            'title_report'   => $titleReport,
            'comment_report' => $commentReport,
            'anomaly_report' => $anomalyReport,
            'prediction'     => $prediction,
            'seo_report'     => $seoReport,
            'search_terms'   => $searchTerms,
            'meta_history'   => $metaHistory,
        ]);
    }

    /**
     * Builds a timeline of meta changes enriched with performance metrics for each period.
     * Each entry: snapshot + avg_views_day + avg_ctr + total_views + days_active + is_best
     */
    private function buildMetaHistory(array $snapshots, array $metrics): array
    {
        if (empty($snapshots)) return [];

        // Index metrics by date string for fast lookup
        $metricsByDate = [];
        foreach ($metrics as $m) {
            $metricsByDate[$m->getDate()->format('Y-m-d')] = $m;
        }

        $history = [];
        $count   = count($snapshots);

        foreach ($snapshots as $i => $snapshot) {
            $from    = $snapshot->getRecordedAt()->setTime(0, 0, 0);
            $to      = isset($snapshots[$i + 1])
                ? $snapshots[$i + 1]->getRecordedAt()->setTime(0, 0, 0)
                : new \DateTimeImmutable();

            // Aggregate metrics in the period [from, to)
            $periodViews = 0;
            $periodCtrs  = [];
            $days        = 0;

            $cursor = $from;
            while ($cursor < $to) {
                $key = $cursor->format('Y-m-d');
                if (isset($metricsByDate[$key])) {
                    $m = $metricsByDate[$key];
                    $periodViews += $m->getViews();
                    if ($m->getCtr() !== null) $periodCtrs[] = $m->getCtr();
                    $days++;
                }
                $cursor = $cursor->modify('+1 day');
            }

            $history[] = [
                'snapshot'       => $snapshot,
                'from'           => $from,
                'to'             => $to,
                'is_current'     => ($i === $count - 1),
                'total_views'    => $periodViews,
                'avg_views_day'  => $days > 0 ? round($periodViews / $days) : 0,
                'avg_ctr'        => !empty($periodCtrs) ? round(array_sum($periodCtrs) / count($periodCtrs), 2) : null,
                'days_active'    => (int) $from->diff($to)->days,
            ];
        }

        // Mark the best performing period by avg_views_day
        if (!empty($history)) {
            $best = array_keys($history, max(array_column($history, 'avg_views_day')))[0];
            $history[$best]['is_best'] = true;
        }

        return array_reverse($history); // most recent first
    }

    #[Route('/alerts', name: 'analytics_alerts')]
    public function alerts(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $reports = $this->aiReportRepo->findForUser($user, 200);
        $anomalies = array_filter($reports, fn($r) => $r->getType() === AiReportType::Anomaly && $r->getStatus()->value === 'done');

        return $this->render('analytics/alerts.html.twig', [
            'anomalies' => array_values($anomalies),
        ]);
    }

    #[Route('/ai-costs', name: 'analytics_ai_costs')]
    public function aiCosts(Request $request): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $reports = $this->aiReportRepo->findForUser($user, 500);
        $monthly = $this->aiReportRepo->getMonthlyStats($user);

        // Pricing per model ($/M tokens) — source: Anthropic pricing page
        $pricing = [
            'claude-haiku-4-5-20251001' => ['input' => 0.80,  'output' => 4.0],
            'claude-sonnet-4-6'         => ['input' => 3.0,   'output' => 15.0],
            'claude-sonnet-4-20250514'  => ['input' => 3.0,   'output' => 15.0],
        ];
        $defaultPricing = ['input' => 3.0, 'output' => 15.0];

        $byModel  = $this->aiReportRepo->getMonthlyStatsByModel($user);
        $costUsd  = 0.0;
        foreach ($byModel as $row) {
            $p        = $pricing[$row['model']] ?? $defaultPricing;
            $costUsd += ($row['tokens_input'] / 1_000_000 * $p['input'])
                      + ($row['tokens_output'] / 1_000_000 * $p['output']);
        }
        $costUsd      = round($costUsd, 4);
        $inputTokens  = (int)($monthly['tokens_input'] ?? 0);
        $outputTokens = (int)($monthly['tokens_output'] ?? 0);

        // Forecast = last month's actual cost (same cron cadence, same video volume)
        // Fallback: average cost per analysis run × number of runs already done this month
        $lastMonthByModel = $this->aiReportRepo->getLastMonthStatsByModel($user);
        $forecastUsd      = 0.0;
        $forecastBasis    = 'last_month';

        if (!empty($lastMonthByModel)) {
            foreach ($lastMonthByModel as $row) {
                $p             = $pricing[$row['model']] ?? $defaultPricing;
                $forecastUsd  += ($row['tokens_input'] / 1_000_000 * $p['input'])
                               + ($row['tokens_output'] / 1_000_000 * $p['output']);
            }
        } else {
            // No last month data: cost per run × runs done so far (extrapolate to end of month)
            // A "run" = a distinct day where analyses were generated
            $runsThisMonth = $this->aiReportRepo->countDistinctRunDaysThisMonth($user);
            $forecastBasis = 'runs';
            if ($runsThisMonth > 0) {
                $costPerRun  = $costUsd / $runsThisMonth;
                $today       = new \DateTimeImmutable();
                $dayOfMonth  = (int) $today->format('j');
                $daysInMonth = (int) $today->format('t');
                // Estimate remaining runs: same cadence as observed
                $expectedTotalRuns = round($runsThisMonth / $dayOfMonth * $daysInMonth);
                $forecastUsd       = $costPerRun * $expectedTotalRuns;
            }
        }
        $forecastUsd = round($forecastUsd, 4);

        $today       = new \DateTimeImmutable();
        $dayOfMonth  = (int) $today->format('j');
        $daysInMonth = (int) $today->format('t');

        return $this->render('analytics/ai_costs.html.twig', [
            'reports'        => $reports,
            'monthly'        => $monthly,
            'cost_usd'       => $costUsd,
            'forecast_usd'   => $forecastUsd,
            'forecast_basis' => $forecastBasis,
            'day_of_month'   => $dayOfMonth,
            'days_in_month'  => $daysInMonth,
            'input_tokens'   => $inputTokens,
            'output_tokens'  => $outputTokens,
            'by_model'       => $byModel,
            'pricing'        => $pricing,
        ]);
    }
}
