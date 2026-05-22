<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Entity\User;
use App\Entity\Video;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AiAnalysisService
{
    private bool $force = false;

    /** Returns the dedup window in hours based on video age: 24h if < 30 days, 720h (30 days) otherwise. */
    private function dedupHours(Video $video): int
    {
        $pub = $video->getPublishedAt();
        if (!$pub) return 24;
        $daysOld = (int) (new \DateTimeImmutable())->diff($pub)->days;
        return $daysOld < 30 ? 24 : 720;
    }

    public function __construct(
        private readonly AnthropicService $anthropic,
        private readonly EntityManagerInterface $em,
        private readonly AiReportRepository $aiReportRepo,
        private readonly VideoRepository $videoRepo,
        private readonly DailyMetricRepository $dailyMetricRepo,
        private readonly CommentRepository $commentRepo,
        private readonly LoggerInterface $logger,
        private readonly float $ctrThreshold = 4.0,
    ) {}

    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    /** Run all analyses (except upload_schedule which is weekly-only). */
    public function analyzeAll(User $user): array
    {
        $skipped = [];
        $counts  = [
            'title_optimization' => $this->runTitleOptimization($user, $skipped),
            'comment_analysis'   => $this->runCommentAnalysis($user, $skipped),
            'anomaly'            => $this->runAnomalyDetection($user, $skipped),
            'prediction'         => $this->runPredictions($user, $skipped),
        ];
        return ['counts' => $counts, 'skipped' => $skipped];
    }

    /** Run a single analysis type. */
    public function analyzeType(User $user, AiReportType $type): array
    {
        $skipped = [];
        $count   = match($type) {
            AiReportType::TitleOptimization => $this->runTitleOptimization($user, $skipped),
            AiReportType::CommentAnalysis   => $this->runCommentAnalysis($user, $skipped),
            AiReportType::Anomaly           => $this->runAnomalyDetection($user, $skipped),
            AiReportType::Prediction        => $this->runPredictions($user, $skipped),
            AiReportType::UploadSchedule    => $this->runUploadSchedule($user, $skipped),
        };
        return ['counts' => [$type->value => $count], 'skipped' => $skipped];
    }

    // ─── Analysis 1: Title Optimization ────────────────────────────────────

    private function runTitleOptimization(User $user, array &$skipped): int
    {
        $videos    = $this->videoRepo->findForUser($user);
        $topVideos = $this->videoRepo->findTopPerformingForUser($user, 5);
        $topRef    = $this->buildTopVideosReference($topVideos);
        $count     = 0;

        foreach ($videos as $video) {
            $metric = $this->dailyMetricRepo->findLatestForVideo($video);
            $ctr    = $metric?->getCtr();

            // Skip only if CTR is known AND above threshold
            if (!$this->force && $ctr !== null && $ctr >= $this->ctrThreshold) {
                $skipped[] = sprintf('[title_optimization] "%s" — CTR %.2f%% >= seuil %.1f%%',
                    $video->getTitle(), $ctr, $this->ctrThreshold
                );
                continue;
            }

            $dedupH = $this->dedupHours($video);
            if (!$this->force && $this->aiReportRepo->findRecentDone($video, AiReportType::TitleOptimization, $dedupH)) {
                $skipped[] = sprintf('[title_optimization] "%s" — rapport done < %dh', $video->getTitle(), $dedupH);
                continue;
            }

            $report = $this->createReport($video, AiReportType::TitleOptimization);
            $prompt = $this->anthropic->loadPrompt('title_optimization', [
                'title'              => $video->getTitle(),
                'description'        => $video->getDescription() ?? '',
                'ctr'                => number_format($ctr ?? 0, 2),
                'impressions'        => $metric?->getImpressions() ?? 0,
                'watch_time_minutes' => $metric?->getWatchTimeMinutes() ?? 0,
                'avg_retention'      => number_format($metric?->getAvgRetentionPercent() ?? 0, 1),
                'top_videos'         => $topRef,
            ]);

            $this->anthropic->call($report, $prompt, AnthropicService::MODEL_BALANCED);
            $this->em->flush();
            $count++;
        }

        return $count;
    }

    // ─── Analysis 2: Comment Analysis ──────────────────────────────────────

    private function runCommentAnalysis(User $user, array &$skipped): int
    {
        $videos = $this->videoRepo->findForUser($user);
        $count  = 0;

        foreach ($videos as $video) {
            $dedupH         = $this->dedupHours($video);
            $lastReport     = $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, $dedupH);
            $lastAnalysisAt = $lastReport?->getGeneratedAt();

            if (!$this->force && $lastReport) {
                $skipped[] = sprintf('[comment_analysis] "%s" — rapport done < %dh', $video->getTitle(), $dedupH);
                continue;
            }

            $newCount = $this->commentRepo->countNewSinceLastAnalysis($video, $this->force ? null : $lastAnalysisAt);
            if ($newCount === 0) {
                $skipped[] = sprintf('[comment_analysis] "%s" — 0 nouveau(x) commentaire(s)', $video->getTitle());
                continue;
            }

            $comments = $this->commentRepo->findNewForVideo($video, $this->force ? null : $lastAnalysisAt);
            if (empty($comments)) continue;

            $commentTexts = implode("\n---\n", array_map(fn($c) => $c->getText(), $comments));

            $report = $this->createReport($video, AiReportType::CommentAnalysis);
            $prompt = $this->anthropic->loadPrompt('comment_analysis', [
                'title'    => $video->getTitle(),
                'comments' => $commentTexts,
            ]);

            $this->anthropic->call($report, $prompt, AnthropicService::MODEL_FULL);
            $this->em->flush();
            $count++;
        }

        return $count;
    }

    // ─── Analysis 3: Anomaly Detection ─────────────────────────────────────

    private function runAnomalyDetection(User $user, array &$skipped): int
    {
        $activeVideos = $this->videoRepo->findRecentForUser($user, 90);
        $baseline     = $this->dailyMetricRepo->getEarlyViewsBaseline($user, 10);
        $count        = 0;

        if (!$this->force && count($baseline) < 3) {
            $skipped[] = sprintf('[anomaly] Pas assez de baseline (%d vidéos avec données J+1/J+3/J+7, minimum 3 requis)', count($baseline));
            return 0;
        }

        [$avgJ1, $stdJ1] = $this->meanStd(array_column($baseline, 'j1'));
        [$avgJ3, $stdJ3] = $this->meanStd(array_column($baseline, 'j3'));
        [$avgJ7, $stdJ7] = $this->meanStd(array_column($baseline, 'j7'));

        foreach ($activeVideos as $video) {
            $dedupH = $this->dedupHours($video);
            if (!$this->force && $this->aiReportRepo->findRecentDone($video, AiReportType::Anomaly, $dedupH)) {
                $skipped[] = sprintf('[anomaly] "%s" — rapport done < %dh', $video->getTitle(), $dedupH);
                continue;
            }

            $metrics = $this->dailyMetricRepo->findForVideo($video, 10);
            if (empty($metrics)) {
                $skipped[] = sprintf('[anomaly] "%s" — aucune DailyMetric', $video->getTitle());
                continue;
            }

            $j1Views = $this->getViewsAtDaysAfterPub($video, $metrics, 1);
            $j3Views = $this->getViewsAtDaysAfterPub($video, $metrics, 3);
            $j7Views = $this->getViewsAtDaysAfterPub($video, $metrics, 7);

            $anomaly = false;
            if ($stdJ7 > 0 && abs($j7Views - $avgJ7) > 2 * $stdJ7) $anomaly = true;
            if ($stdJ3 > 0 && abs($j3Views - $avgJ3) > 2 * $stdJ3) $anomaly = true;

            if (!$anomaly && !$this->force) {
                $skipped[] = sprintf('[anomaly] "%s" — dans la normale (J7: %d vues, moy: %d ±%d)',
                    $video->getTitle(), $j7Views, round($avgJ7), round($stdJ7)
                );
                continue;
            }

            $latestMetric = $this->dailyMetricRepo->findLatestForVideo($video);
            $trafficSrc   = $latestMetric?->getTrafficSources() ? json_encode($latestMetric->getTrafficSources()) : 'N/A';

            $report = $this->createReport($video, AiReportType::Anomaly);
            $prompt = $this->anthropic->loadPrompt('anomaly', [
                'title'           => $video->getTitle(),
                'published_day'   => $video->getPublishedAt()?->format('l') ?? 'N/A',
                'published_time'  => $video->getPublishedAt()?->format('H:i') ?? 'N/A',
                'views_j1'        => $j1Views,
                'views_j3'        => $j3Views,
                'views_j7'        => $j7Views,
                'avg_j1'          => round($avgJ1),
                'avg_j3'          => round($avgJ3),
                'avg_j7'          => round($avgJ7),
                'traffic_sources' => $trafficSrc,
            ]);

            $this->anthropic->call($report, $prompt, AnthropicService::MODEL_FULL);
            $this->em->flush();
            $count++;
        }

        return $count;
    }

    // ─── Analysis 4: Prediction ─────────────────────────────────────────────

    private function runPredictions(User $user, array &$skipped): int
    {
        $ninetyDaysAgo = new \DateTimeImmutable('-90 days');
        $videos        = $this->videoRepo->findForUser($user);
        $refCurves     = $this->buildReferenceCurves($this->videoRepo->findRecentForUser($user, 90));
        $count         = 0;

        foreach ($videos as $video) {
            $pub = $video->getPublishedAt();
            if (!$pub || $pub < $ninetyDaysAgo) continue;

            $dedupH = $this->dedupHours($video);
            if (!$this->force && $this->aiReportRepo->findRecentDone($video, AiReportType::Prediction, $dedupH)) {
                $skipped[] = sprintf('[prediction] "%s" — rapport done < %dh', $video->getTitle(), $dedupH);
                continue;
            }

            $metric = $this->dailyMetricRepo->findLatestForVideo($video);
            if (!$metric) {
                $skipped[] = sprintf('[prediction] "%s" — aucune DailyMetric', $video->getTitle());
                continue;
            }

            $report = $this->createReport($video, AiReportType::Prediction);
            $prompt = $this->anthropic->loadPrompt('prediction', [
                'title'              => $video->getTitle(),
                'views_j2'           => $metric->getViews(),
                'ctr'                => number_format($metric->getCtr() ?? 0, 2),
                'watch_time_minutes' => $metric->getWatchTimeMinutes() ?? 0,
                'subscribers_gained' => $metric->getSubscribersGained() ?? 0,
                'reference_curves'   => $refCurves,
            ]);

            $this->anthropic->call($report, $prompt, AnthropicService::MODEL_FAST);
            $this->em->flush();
            $count++;
        }

        return $count;
    }

    // ─── Analysis 5: Upload Schedule ────────────────────────────────────────

    public function runUploadSchedule(User $user, array &$skipped = []): int
    {
        if (!$this->force && $this->aiReportRepo->findRecentDone(null, AiReportType::UploadSchedule)) {
            $skipped[] = '[upload_schedule] Rapport done < 24h — utilise --force ou attends dimanche';
            return 0;
        }

        $videos = $this->videoRepo->findRecentForUser($user, 90);
        if (empty($videos)) {
            $skipped[] = '[upload_schedule] Aucune vidéo des 90 derniers jours';
            return 0;
        }

        $videosData = '';
        foreach ($videos as $video) {
            $metric = $this->dailyMetricRepo->findLatestForVideo($video);
            $pub    = $video->getPublishedAt();
            if (!$pub) continue;
            $videosData .= sprintf(
                "- \"%s\" | Jour: %s | Heure: %s | Vues J+7: %d | CTR: %.2f%% | Watch Time: %d min\n",
                $video->getTitle(),
                $pub->format('l'),
                $pub->format('H:i'),
                $metric?->getViews() ?? 0,
                $metric?->getCtr() ?? 0,
                $metric?->getWatchTimeMinutes() ?? 0,
            );
        }

        $report = $this->createReport(null, AiReportType::UploadSchedule);
        $prompt = $this->anthropic->loadPrompt('upload_schedule', ['videos_data' => $videosData]);
        $this->anthropic->call($report, $prompt, AnthropicService::MODEL_BALANCED);
        $this->em->flush();

        return 1;
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function createReport(?Video $video, AiReportType $type): AiReport
    {
        $report = new AiReport();
        $report->setVideo($video)
            ->setType($type)
            ->setStatus(AiReportStatus::Pending)
            ->setGeneratedAt(new \DateTimeImmutable());
        $this->em->persist($report);
        return $report;
    }

    private function buildTopVideosReference(array $videos): string
    {
        $lines = [];
        foreach ($videos as $video) {
            $metric  = $this->dailyMetricRepo->findLatestForVideo($video);
            $lines[] = sprintf('- "%s" | CTR: %.2f%% | Watch Time: %d min',
                $video->getTitle(),
                $metric?->getCtr() ?? 0,
                $metric?->getWatchTimeMinutes() ?? 0,
            );
        }
        return implode("\n", $lines);
    }

    private function buildReferenceCurves(array $videos): string
    {
        $lines = [];
        foreach (array_slice($videos, 0, 10) as $video) {
            $metrics = $this->dailyMetricRepo->findForVideo($video, 30);
            if (empty($metrics)) continue;
            $j2  = $this->getViewsAtDaysAfterPub($video, $metrics, 2);
            $j30 = end($metrics)->getViews();
            $lines[] = sprintf('- "%s" | J+2: %d vues → J+30: %d vues', $video->getTitle(), $j2, $j30);
        }
        return implode("\n", $lines) ?: 'Pas encore assez de données historiques.';
    }

    private function getViewsAtDaysAfterPub(Video $video, array $metrics, int $daysAfter): int
    {
        $pub = $video->getPublishedAt();
        if (!$pub) return 0;
        $target = $pub->modify("+{$daysAfter} days")->setTime(0, 0, 0);
        foreach ($metrics as $m) {
            if ($m->getDate()->format('Y-m-d') === $target->format('Y-m-d')) return $m->getViews();
        }
        return 0;
    }

    private function meanStd(array $values): array
    {
        $n = count($values);
        if ($n === 0) return [0, 0];
        $mean     = array_sum($values) / $n;
        $variance = 0;
        foreach ($values as $v) {
            $variance += ($v - $mean) ** 2;
        }
        return [$mean, $n > 1 ? sqrt($variance / ($n - 1)) : 0];
    }
}
