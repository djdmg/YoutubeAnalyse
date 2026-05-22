<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\RetentionPointRepository;
use App\Repository\VideoRepository;
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
    ) {}

    #[Route('/videos', name: 'analytics_videos')]
    public function list(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $sortBy = $request->query->get('sort', 'views');
        $videos = $this->videoRepo->findForUser($user);

        $videosData = [];
        foreach ($videos as $video) {
            $metric    = $this->metricRepo->findLatestForVideo($video);
            $anomaly   = $this->aiReportRepo->findRecentDone($video, AiReportType::Anomaly, 168); // 7 days
            $sentiment = $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, 168);
            $prediction= $this->aiReportRepo->findRecentDone($video, AiReportType::Prediction, 720);

            $videosData[] = [
                'video'      => $video,
                'metric'     => $metric,
                'anomaly'    => $anomaly,
                'sentiment'  => $sentiment,
                'prediction' => $prediction,
            ];
        }

        usort($videosData, function ($a, $b) use ($sortBy) {
            $ma = $a['metric'];
            $mb = $b['metric'];
            return match($sortBy) {
                'ctr'         => ($mb?->getCtr() ?? 0) <=> ($ma?->getCtr() ?? 0),
                'watch_time'  => ($mb?->getWatchTimeMinutes() ?? 0) <=> ($ma?->getWatchTimeMinutes() ?? 0),
                'date'        => ($b['video']->getPublishedAt() ?? new \DateTimeImmutable('1970-01-01')) <=> ($a['video']->getPublishedAt() ?? new \DateTimeImmutable('1970-01-01')),
                default       => ($mb?->getViews() ?? 0) <=> ($ma?->getViews() ?? 0),
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
        ]);
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
