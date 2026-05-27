<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Entity\User;
use App\Entity\Video;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\AppSettingRepository;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSearchTermRepository;
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
        private readonly AiProviderInterface $anthropic,
        private readonly EntityManagerInterface $em,
        private readonly AiReportRepository $aiReportRepo,
        private readonly VideoRepository $videoRepo,
        private readonly DailyMetricRepository $dailyMetricRepo,
        private readonly CommentRepository $commentRepo,
        private readonly VideoSearchTermRepository $searchTermRepo,
        private readonly AppSettingRepository $settingRepo,
        private readonly LoggerInterface $logger,
        private readonly float $ctrThreshold = 4.0,
    ) {}

    public function setForce(bool $force): void
    {
        $this->force = $force;
    }

    /** Returns the configured model for a given task, falling back to the provided default tier. */
    private function modelFor(AiReportType $type, string $default = AiProviderInterface::MODEL_BALANCED): string
    {
        return $this->settingRepo->get('ai_model_' . $type->value) ?? $default;
    }

    /** Run all analyses (except upload_schedule which is weekly-only). */
    public function analyzeAll(User $user): array
    {
        $skipped = [];
        $counts  = [
            'title_optimization'       => $this->runTitleOptimization($user, $skipped),
            'comment_analysis'         => $this->runCommentAnalysis($user, $skipped),
            'anomaly'                  => $this->runAnomalyDetection($user, $skipped),
            'prediction'               => $this->runPredictions($user, $skipped),
            'seo_optimization'         => $this->runSeoOptimization($user, $skipped),
            'thumbnail_analysis'       => $this->runThumbnailAnalysis($user, $skipped),
            'description_optimization' => $this->runDescriptionOptimization($user, $skipped),
        ];
        return ['counts' => $counts, 'skipped' => $skipped];
    }

    /** Run a single analysis type. */
    public function analyzeType(User $user, AiReportType $type): array
    {
        $skipped = [];
        $count   = match($type) {
            AiReportType::TitleOptimization      => $this->runTitleOptimization($user, $skipped),
            AiReportType::CommentAnalysis        => $this->runCommentAnalysis($user, $skipped),
            AiReportType::Anomaly                => $this->runAnomalyDetection($user, $skipped),
            AiReportType::Prediction             => $this->runPredictions($user, $skipped),
            AiReportType::UploadSchedule         => $this->runUploadSchedule($user, $skipped),
            AiReportType::SeoOptimization        => $this->runSeoOptimization($user, $skipped),
            AiReportType::ThumbnailAnalysis      => $this->runThumbnailAnalysis($user, $skipped),
            AiReportType::DescriptionOptimization => $this->runDescriptionOptimization($user, $skipped),
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

            $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::TitleOptimization, AiProviderInterface::MODEL_BALANCED));
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

            $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::CommentAnalysis, AiProviderInterface::MODEL_FULL));
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

            $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::Anomaly, AiProviderInterface::MODEL_FULL));
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

            $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::Prediction, AiProviderInterface::MODEL_FAST));
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
        $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::UploadSchedule, AiProviderInterface::MODEL_BALANCED));
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

    // ─── Analysis 7: Thumbnail Analysis (Vision) ────────────────────────────

    public function runThumbnailAnalysis(User $user, array &$skipped = []): int
    {
        $videos = $this->videoRepo->findForUser($user);
        $count  = 0;

        foreach ($videos as $video) {
            $thumbUrl = $video->getThumbnailUrl();
            if (!$thumbUrl) {
                $skipped[] = sprintf('[thumbnail_analysis] "%s" — pas de miniature', $video->getTitle());
                continue;
            }

            if (!$this->force && $this->aiReportRepo->findRecentDone($video, AiReportType::ThumbnailAnalysis, 720)) {
                $skipped[] = sprintf('[thumbnail_analysis] "%s" — rapport done < 30j', $video->getTitle());
                continue;
            }

            $prompt = <<<PROMPT
Tu es un expert en marketing visuel YouTube. Analyse cette miniature de vidéo YouTube et retourne UNIQUEMENT un JSON valide sans texte avant ni après :
{
  "score": 7,
  "force": ["visage expressif", "couleurs vives"],
  "faiblesse": ["texte illisible sur mobile", "trop chargée"],
  "recommandation": "Simplifie le texte et agrandis les visages pour améliorer le CTR."
}
Le score est de 1 à 10 (10 = miniature parfaite). Sois concis et factuel.
PROMPT;

            $result = $this->anthropic->callVision($thumbUrl, $prompt, $this->modelFor(AiReportType::ThumbnailAnalysis, AiProviderInterface::MODEL_FAST));

            if (!$result) {
                $skipped[] = sprintf('[thumbnail_analysis] "%s" — appel vision échoué', $video->getTitle());
                continue;
            }

            $report = $this->createReport($video, AiReportType::ThumbnailAnalysis);
            $report->setPayload($result);
            $report->setStatus(\App\Enum\AiReportStatus::Done);
            $report->setModelVersion($this->modelFor(AiReportType::ThumbnailAnalysis, AiProviderInterface::MODEL_FAST));
            $this->em->flush();
            $count++;
        }

        return $count;
    }

    // ─── Analysis 8: Description Optimization ───────────────────────────────

    public function runDescriptionOptimization(User $user, array &$skipped = []): int
    {
        $videos = $this->videoRepo->findForUser($user);
        $count  = 0;

        foreach ($videos as $video) {
            if (!$this->force && $this->aiReportRepo->findRecentDone($video, AiReportType::DescriptionOptimization, 720)) {
                $skipped[] = sprintf('[description_optimization] "%s" — rapport done < 30j', $video->getTitle());
                continue;
            }

            $terms        = $this->searchTermRepo->findTopForVideo($video, 20);
            $termsList    = empty($terms) ? 'Aucune donnée de recherche disponible.' : implode("\n", array_map(
                fn($t) => sprintf('- "%s" (%d vues)', $t->getQuery(), $t->getViews()),
                $terms
            ));
            $description  = mb_substr($video->getDescription() ?? '', 0, 600);

            $prompt = <<<PROMPT
Tu es un expert en SEO YouTube. Optimise la description de cette vidéo pour maximiser la visibilité.

Titre : "{$video->getTitle()}"
Description actuelle :
{$description}

Requêtes de recherche performantes :
{$termsList}

Génère une description optimisée qui :
- Intègre naturellement les mots-clés des requêtes
- Commence par une accroche percutante (visible avant "Voir plus")
- Est structurée avec des paragraphes clairs
- Inclut un appel à l'action

Réponds UNIQUEMENT en JSON valide, sans texte avant ni après :
{
  "description_optimisee": "...",
  "mots_cles_integres": ["...", "..."],
  "ameliorations": ["...", "..."]
}
PROMPT;

            $report = $this->createReport($video, AiReportType::DescriptionOptimization);
            $result = $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::DescriptionOptimization, AiProviderInterface::MODEL_BALANCED));

            if ($result) {
                $this->em->flush();
                $count++;
            } else {
                $this->em->flush();
            }
        }

        return $count;
    }

    // ─── Analysis 6: SEO Optimization (search terms) ───────────────────────

    private function runSeoOptimization(User $user, array &$skipped): int
    {
        $videos  = $this->videoRepo->findForUser($user);
        $count   = 0;

        foreach ($videos as $video) {
            $dedupH = $this->dedupHours($video);

            if (!$this->force && $this->aiReportRepo->findRecentDone($video, AiReportType::SeoOptimization, $dedupH)) {
                $skipped[] = ['video' => $video->getTitle(), 'type' => 'seo_optimization', 'reason' => 'recent'];
                continue;
            }

            $terms = $this->searchTermRepo->findTopForVideo($video, 25);
            if (empty($terms)) {
                $skipped[] = ['video' => $video->getTitle(), 'type' => 'seo_optimization', 'reason' => 'no_search_terms'];
                continue;
            }

            $termsList = implode("\n", array_map(
                fn($t) => sprintf('- "%s" (%d vues)', $t->getQuery(), $t->getViews()),
                $terms
            ));

            $latestMetric = $this->dailyMetricRepo->findLatestForVideo($video);
            $trafficBreakdown = '';
            if ($latestMetric?->getTrafficSources()) {
                arsort($latestMetric->getTrafficSources());
                foreach (array_slice($latestMetric->getTrafficSources(), 0, 5, true) as $src => $views) {
                    $trafficBreakdown .= sprintf("- %s : %d vues\n", str_replace('_', ' ', strtolower($src)), $views);
                }
            }

            $description = mb_substr($video->getDescription() ?? '', 0, 800);

            $prompt = <<<PROMPT
Tu es un expert en optimisation SEO pour YouTube.

Voici les données d'une vidéo YouTube :

Titre actuel : "{$video->getTitle()}"
Description actuelle (extrait) :
{$description}

Requêtes de recherche YouTube qui ont amené des spectateurs sur cette vidéo (du plus au moins fréquent) :
{$termsList}

Sources de trafic (répartition) :
{$trafficBreakdown}

Analyse ces données et propose :
1. Un titre optimisé qui intègre naturellement les requêtes les plus performantes
2. Les mots-clés manquants à ajouter dans la description (ceux des requêtes non présents dans le titre/description)
3. Un extrait de description optimisé pour le SEO (premières 150 mots — visibles avant "Voir plus")
4. Un diagnostic : pourquoi ces requêtes performent, qu'est-ce qui attire les spectateurs

Réponds UNIQUEMENT en JSON valide, sans texte avant ni après :
{
  "titre_optimise": "...",
  "mots_cles_manquants": ["...", "..."],
  "description_optimisee": "...",
  "diagnostic": "...",
  "requetes_principales": ["...", "..."]
}
PROMPT;

            $report = (new AiReport())
                ->setVideo($video)
                ->setType(AiReportType::SeoOptimization)
                ->setStatus(AiReportStatus::Pending)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($report);

            $result = $this->anthropic->call($report, $prompt, $this->modelFor(AiReportType::SeoOptimization, AiProviderInterface::MODEL_BALANCED));

            if ($result) {
                $this->em->flush();
                $count++;
                $this->logger->info('SEO analysis done', ['video' => $video->getTitle()]);
            } else {
                $this->em->flush();
            }
        }

        return $count;
    }
}
