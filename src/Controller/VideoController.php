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
use App\Repository\AppSettingRepository;
use App\Entity\AiReport;
use App\Entity\ThumbnailChange;
use App\Enum\AiReportStatus;
use App\Repository\VideoSearchTermRepository;
use App\Service\GeminiService;
use App\Service\YouTubeDataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use App\Message\GenerateThumbnailMessage;
use App\Message\RunAiAnalysisMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
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
        private readonly GeminiService $gemini,
        private readonly AppSettingRepository $settingRepo,
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly YouTubeDataService $youtubeData,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    /**
     * Returns ['prompt' => string, 'meta_prompt' => string].
     */
    private function generateThumbnailPrompt(mixed $video): array
    {
        $description = $video->getDescription()
            ? mb_substr($video->getDescription(), 0, 1200)
            : '';

        $promptModel = $this->settingRepo->get(GeminiService::SETTING_PROMPT_MODEL) ?? 'balanced';

        $aiPrompt = <<<PROMPT
You are both a YouTube thumbnail expert and an AI image-generation prompt engineer.
Your mission: write a single, extremely detailed image generation prompt that produces a thumbnail where ANY viewer instantly understands what the video is about — without reading any text.

━━ VIDEO CONTENT ━━
Title: {$video->getTitle()}
Description: {$description}

━━ STEP 1 — EXTRACT SPECIFIC VISUAL ELEMENTS (think, don't write this part) ━━
From the title and description, identify:
• The ONE central subject that defines this video's topic (specific instrument, object, location, event, technique — NOT a concept)
• 2–3 supporting visual elements mentioned or implied in the description
• The dominant mood/atmosphere (e.g. epic, nostalgic, electric, gritty, euphoric)
• The single most content-specific text overlay (3–5 words max from the actual content)

━━ STEP 2 — WRITE THE IMAGE GENERATION PROMPT ━━
Structure the prompt in this order:
1. MAIN SUBJECT: Describe the central visual element in extreme detail — material, texture, color, state, position in frame (close-up foreground, center). Be hyper-specific: not "a guitar" but "a battered 1970s sunburst Fender Stratocaster with worn frets, center frame".
2. SCENE & BACKGROUND: Specific setting that instantly places the viewer — real environment, identifiable backdrop, depth (e.g. "smoke-filled concert hall with blurred stage lights", "rain-soaked Tokyo street at night").
3. LIGHTING & COLOR PALETTE: Choose lighting that maximises visual drama and matches the mood — be specific (golden backlight, cold neon glow, single spotlight, hazy stage lighting).
4. COMPOSITION: Camera angle and framing (extreme close-up, low angle, rule of thirds), what draws the eye first.
5. TEXT OVERLAY: Exactly this format — bold display typography overlay reading "[SPECIFIC TEXT]", placed [position], [color] on [contrasting background/shadow]. The text must be the most impactful phrase from the content.
6. TECHNICAL TAIL: End with "no human faces, no celebrity likenesses, 16:9 YouTube thumbnail format, hyper-realistic photo quality, ultra-sharp, cinematic depth of field, no watermarks, no logos."

━━ RULES ━━
— Every visual detail must come from the actual content — zero generic or abstract imagery
— The thumbnail must tell the story at a glance: topic, mood, and era all visible simultaneously
— Bold, contrasted, visually striking — built to stop a scroll
— No real human faces or celebrity likenesses
— Output: ONE flowing English paragraph of 5–8 sentences. No headers. No bullet points. No explanation.

Reply with ONLY the image prompt.
PROMPT;

        $report = new AiReport();
        $report->setType(AiReportType::ThumbnailPrompt);
        $report->setVideo($video);
        $report->setStatus(AiReportStatus::Pending);

        try {
            $result = $this->gemini->callRawTextFull($aiPrompt, $promptModel, 1.0);
            $prompt = $result['text'];
            $report->setModelVersion($result['model']);
            $report->setTokensInput($result['tokensInput']);
            $report->setTokensOutput($result['tokensOutput']);
            $report->setDurationMs($result['durationMs']);
            $report->setStatus(AiReportStatus::Done);
        } catch (\Throwable $e) {
            $this->logger->warning('Thumbnail prompt generation failed, using PHP fallback', ['error' => $e->getMessage()]);
            $report->setStatus(AiReportStatus::Failed);
            $prompt = $this->buildFallbackPrompt($video);
        }

        $this->em->persist($report);
        $this->em->flush();

        return ['prompt' => $prompt, 'meta_prompt' => $aiPrompt];
    }

    private function buildFallbackPrompt(mixed $video): string
    {
        $title = $video->getTitle();
        $desc  = $video->getDescription() ? mb_substr($video->getDescription(), 0, 600) : '';

        $stopWords = ['the','a','an','and','or','of','in','on','at','to','for','with','by','is','was','are','were',
                      'le','la','les','de','du','des','un','une','et','ou','en','au','aux','ce','se','sa','son','ses',
                      'pour','avec','sur','dans','par','mix','vol','feat','ft','this','that','from','its','also','more'];
        $text = strtolower($title . ' ' . $desc);
        preg_match_all('/\b[a-záàâäéèêëîïôöùûü]{4,}\b/u', $text, $matches);
        $words = array_values(array_diff(array_unique($matches[0]), $stopWords));

        $scene   = implode(', ', array_slice($words, 0, 7));
        $subject = array_slice($words, 0, 3);

        preg_match('/20\d\d/', $title, $yearMatch);
        if ($yearMatch) {
            $overlayText = $yearMatch[0];
        } elseif (count($subject) >= 2) {
            $overlayText = strtoupper($subject[0] . ' ' . $subject[1]);
        } else {
            $overlayText = strtoupper($subject[0] ?? 'WATCH');
        }

        return "Hyper-realistic cinematic scene featuring {$scene} as the central visual focus, "
            . "dramatic foreground subject filling 60% of the frame with rich texture and vivid color detail, "
            . "specific atmospheric background with identifiable environment establishing the context, "
            . "bold display typography overlay \"{$overlayText}\" in large white letters with dark shadow, placed upper-third, "
            . "no human faces, no logos. "
            . "16:9 YouTube thumbnail, ultra-sharp, cinematic depth of field, professional photo quality, no watermarks.";
    }

    private function resolveThumbnailModelName(): string
    {
        $id = $this->settingRepo->get(GeminiService::SETTING_THUMBNAIL_MODEL) ?? 'imagen-3.0-generate-001';
        foreach ($this->gemini->getAvailableModels() as $m) {
            if ($m['id'] === $id) return $m['name'];
        }
        return $id;
    }

    #[Route('/videos', name: 'analytics_videos')]
    public function list(Request $request): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $sortBy = $request->query->get('sort', 'views');
        $videos = $this->videoRepo->findForUser($user);

        $listStats  = $this->metricRepo->getListStatsForUser($user);
        $sparklines = $this->metricRepo->getSparklineDataForUser($user, 7);
        $trendData  = $this->metricRepo->getTrendDataForUser($user);

        $videosData = [];
        foreach ($videos as $video) {
            $stats     = $listStats[$video->getId()] ?? ['total_views' => 0, 'avg_ctr' => null, 'total_watch_time' => 0];
            $anomaly   = $this->aiReportRepo->findRecentDone($video, AiReportType::Anomaly, 168);
            $sentiment = $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, 168);
            $prediction= $this->aiReportRepo->findRecentDone($video, AiReportType::Prediction, 720);
            $thumbnail = $this->aiReportRepo->findRecentDone($video, AiReportType::ThumbnailAnalysis, 720);

            $estimatedRevenue = $stats['total_views'] > 0
                ? round($stats['total_views'] * $user->getEstimatedRpm() / 1000, 2)
                : null;

            $videosData[] = [
                'video'             => $video,
                'stats'             => $stats,
                'health_score'      => self::computeHealthScore($stats),
                'health_detail'     => self::computeHealthDetail($stats),
                'sparkline'         => $sparklines[$video->getId()] ?? [],
                'trend'             => $trendData[$video->getId()] ?? null,
                'anomaly'           => $anomaly,
                'sentiment'         => $sentiment,
                'prediction'        => $prediction,
                'thumbnail_report'  => $thumbnail,
                'estimated_revenue' => $estimatedRevenue,
            ];
        }

        usort($videosData, function ($a, $b) use ($sortBy) {
            $sa = $a['stats'];
            $sb = $b['stats'];
            return match($sortBy) {
                'ctr'          => ($sb['avg_ctr'] ?? 0) <=> ($sa['avg_ctr'] ?? 0),
                'watch_time'   => ($sb['total_watch_time'] ?? 0) <=> ($sa['total_watch_time'] ?? 0),
                'date'         => ($b['video']->getPublishedAt() ?? new \DateTimeImmutable('1970-01-01')) <=> ($a['video']->getPublishedAt() ?? new \DateTimeImmutable('1970-01-01')),
                'health'       => $b['health_score'] <=> $a['health_score'],
                'trend'        => ($b['trend']['pct'] ?? 0) <=> ($a['trend']['pct'] ?? 0),
                default        => ($sb['total_views'] ?? 0) <=> ($sa['total_views'] ?? 0),
            };
        });

        return $this->render('analytics/videos.html.twig', [
            'videos_data' => $videosData,
            'sort_by'     => $sortBy,
        ]);
    }

    #[Route('/compare', name: 'analytics_compare')]
    public function compare(Request $request): Response
    {
        /** @var User $user */
        $user      = $this->getUser();
        $youtubeIds = array_filter((array) $request->query->all('ids'));
        $youtubeIds = array_slice($youtubeIds, 0, 4); // max 4 videos

        $videos = [];
        foreach ($youtubeIds as $ytId) {
            $v = $this->videoRepo->findByYoutubeId($ytId);
            if ($v && $v->getUser() === $user) {
                $videos[] = $v;
            }
        }

        $compareData = [];
        if (!empty($videos)) {
            $rawData = $this->metricRepo->getCompareDataForVideos($videos);
            foreach ($videos as $video) {
                $compareData[] = [
                    'video'      => $video,
                    'daily_data' => $rawData[$video->getId()] ?? [],
                ];
            }
        }

        return $this->render('analytics/compare.html.twig', [
            'compare_data' => $compareData,
            'all_videos'   => $this->videoRepo->findForUser($user),
        ]);
    }

    #[Route('/best-time', name: 'analytics_best_time')]
    public function bestTime(): Response
    {
        /** @var User $user */
        $user          = $this->getUser();
        $videos        = $this->videoRepo->findForUser($user);
        $firstWeek     = $this->metricRepo->getFirstWeekViewsByVideo($user);

        $dowData  = []; // day_of_week (1=Mon…7=Sun) => [views]
        $hourData = []; // hour_bucket (0-7, 3h slots) => [views]

        foreach ($videos as $video) {
            $pub     = $video->getPublishedAt();
            $videoId = $video->getId();
            if (!$pub || !isset($firstWeek[$videoId])) continue;

            $views      = $firstWeek[$videoId];
            $dow        = (int) $pub->format('N'); // 1=Mon … 7=Sun
            $hourBucket = intdiv((int) $pub->format('G'), 3); // 0-7

            $dowData[$dow][]        = $views;
            $hourData[$hourBucket][] = $views;
        }

        $dowStats = [];
        foreach ($dowData as $dow => $viewsList) {
            $dowStats[$dow] = [
                'avg'   => (int) round(array_sum($viewsList) / count($viewsList)),
                'count' => count($viewsList),
            ];
        }

        $hourStats = [];
        foreach ($hourData as $bucket => $viewsList) {
            $hourStats[$bucket] = [
                'avg'   => (int) round(array_sum($viewsList) / count($viewsList)),
                'count' => count($viewsList),
            ];
        }

        $maxDowAvg  = $dowStats  ? max(array_column($dowStats,  'avg')) : 1;
        $maxHourAvg = $hourStats ? max(array_column($hourStats, 'avg')) : 1;

        $heatmap    = $this->metricRepo->getHeatmapDataForUser($user);
        $maxHeatmap = 1;
        foreach ($heatmap as $buckets) {
            foreach ($buckets as $cell) {
                if ($cell['avg'] > $maxHeatmap) $maxHeatmap = $cell['avg'];
            }
        }

        return $this->render('analytics/best_time.html.twig', [
            'dow_stats'    => $dowStats,
            'hour_stats'   => $hourStats,
            'max_dow_avg'  => $maxDowAvg,
            'max_hour_avg' => $maxHourAvg,
            'total_videos' => count($videos),
            'analyzed'     => count($firstWeek),
            'heatmap'      => $heatmap,
            'max_heatmap'  => $maxHeatmap,
        ]);
    }

    private static function computeHealthScore(array $stats): int
    {
        $d = self::computeHealthDetail($stats);
        return $d['ctr_pts'] + $d['watch_pts'] + $d['scale_pts'];
    }

    private static function computeHealthDetail(array $stats): array
    {
        $ctr      = (float) ($stats['avg_ctr'] ?? 0);
        $views    = (int)   ($stats['total_views'] ?? 0);
        $watchMin = (int)   ($stats['total_watch_time'] ?? 0);

        $ctrPts        = (int) round(min(40.0, $ctr / 5.0 * 40.0));
        $avgMinPerView = $views > 0 ? $watchMin / $views : 0.0;
        $watchPts      = (int) round(min(30.0, $avgMinPerView / 5.0 * 30.0));
        $scalePts      = $views > 0 ? (int) round(min(30.0, log10(max(1, $views)) / log10(50000) * 30.0)) : 0;

        return [
            'ctr_pts'   => $ctrPts,
            'watch_pts' => $watchPts,
            'scale_pts' => $scalePts,
            'avg_min'   => round($avgMinPerView, 1),
        ];
    }

    #[Route('/videos/export', name: 'analytics_videos_export')]
    public function exportVideos(): Response
    {
        /** @var User $user */
        $user      = $this->getUser();
        $videos    = $this->videoRepo->findForUser($user);
        $listStats = $this->metricRepo->getListStatsForUser($user);

        $rows = [['Titre', 'YouTube ID', 'Publiée le', 'Vues totales', 'CTR moyen (%)', 'Watch Time (min)', 'Score santé']];
        foreach ($videos as $video) {
            $s     = $listStats[$video->getId()] ?? ['total_views' => 0, 'avg_ctr' => null, 'total_watch_time' => 0];
            $rows[] = [
                $video->getTitle(),
                $video->getYoutubeId(),
                $video->getPublishedAt()?->format('Y-m-d') ?? '',
                $s['total_views'],
                $s['avg_ctr'] !== null ? number_format((float) $s['avg_ctr'], 2, '.', '') : '',
                $s['total_watch_time'],
                self::computeHealthScore($s),
            ];
        }

        return $this->csvResponse($rows, 'videos-' . date('Y-m-d') . '.csv');
    }

    #[Route('/videos/{youtubeId}/export', name: 'analytics_video_export')]
    public function exportVideo(string $youtubeId): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);
        if (!$video || $video->getUser() !== $user) {
            throw $this->createNotFoundException();
        }

        $metrics = $this->metricRepo->findForVideo($video, 90);
        $rows    = [['Date', 'Vues', 'CTR (%)', 'Watch Time (min)', 'Abonnés gagnés']];
        foreach ($metrics as $m) {
            $rows[] = [
                $m->getDate()->format('Y-m-d'),
                $m->getViews(),
                $m->getCtr() !== null ? number_format((float) $m->getCtr(), 2, '.', '') : '',
                $m->getWatchTimeMinutes() ?? 0,
                $m->getSubscribersGained() ?? 0,
            ];
        }

        $slug     = preg_replace('/[^a-z0-9]+/i', '-', $video->getTitle());
        $filename = 'metrics-' . substr($slug, 0, 40) . '-' . date('Y-m-d') . '.csv';

        return $this->csvResponse($rows, $filename);
    }

    private function csvResponse(array $rows, string $filename): Response
    {
        $escape = fn(mixed $v): string => '"' . str_replace('"', '""', (string) $v) . '"';
        $csv    = "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
        foreach ($rows as $row) {
            $csv .= implode(',', array_map($escape, $row)) . "\r\n";
        }

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/videos/{youtubeId}/suggest-prompt', name: 'analytics_video_suggest_prompt', methods: ['POST'])]
    public function suggestPrompt(string $youtubeId): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Vidéo introuvable.'], 404);
        }

        try {
            ['prompt' => $prompt, 'meta_prompt' => $metaPrompt] = $this->generateThumbnailPrompt($video);
            return new JsonResponse(['success' => true, 'prompt' => $prompt, 'meta_prompt' => $metaPrompt]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
        }
    }

    #[Route('/videos/{youtubeId}/generate-thumbnail', name: 'analytics_video_generate_thumbnail', methods: ['POST'])]
    public function generateThumbnail(string $youtubeId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Vidéo introuvable.'], 404);
        }

        $prompt = trim($request->request->get('prompt', ''));
        if ($prompt === '') {
            return new JsonResponse(['success' => false, 'message' => 'Le prompt est vide. Utilisez "Suggérer un prompt" pour en générer un.']);
        }

        $model    = $this->settingRepo->get(GeminiService::SETTING_THUMBNAIL_MODEL) ?? 'imagen-3.0-generate-001';
        $jobId    = bin2hex(random_bytes(8));
        $cacheKey = 'thumbnail_job_' . $jobId;

        // Pre-store pending state so the polling endpoint never returns "not found"
        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(600);
            return ['status' => 'pending'];
        });

        try {
            $this->bus->dispatch(new GenerateThumbnailMessage($jobId, $youtubeId, $model, $prompt));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'messenger_messages') || str_contains($msg, "doesn't exist") || str_contains($msg, 'Base table')) {
                $msg = 'Table Messenger manquante. Lancez : php bin/console doctrine:migrations:migrate';
            }
            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        return new JsonResponse(['success' => true, 'jobId' => $jobId]);
    }

    #[Route('/videos/{youtubeId}/thumbnail-status/{jobId}', name: 'analytics_video_thumbnail_status', methods: ['GET'])]
    public function thumbnailStatus(string $youtubeId, string $jobId): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Vidéo introuvable.'], 404);
        }

        $cacheKey = 'thumbnail_job_' . $jobId;
        $result   = $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(0);
            return null;
        });

        if ($result === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Job introuvable ou expiré.']);
        }

        return new JsonResponse($result);
    }

    #[Route('/videos/{youtubeId}/apply-thumbnail', name: 'analytics_video_apply_thumbnail', methods: ['POST'])]
    public function applyThumbnail(string $youtubeId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Vidéo introuvable.'], 404);
        }

        $dir      = $this->projectDir . '/public/uploads/thumbnails/';
        $filename = basename((string) $request->request->get('filename', ''));

        // Fallback: legacy _preview.png
        if ($filename === '') {
            $filename = $youtubeId . '_preview.png';
        }

        $filePath = $dir . $filename;

        // Security: only allow files belonging to this video
        if (!str_starts_with($filename, $youtubeId . '_') || (!str_ends_with($filename, '.png') && !str_ends_with($filename, '.jpg'))) {
            return new JsonResponse(['success' => false, 'message' => 'Fichier invalide.']);
        }

        if (!file_exists($filePath)) {
            return new JsonResponse(['success' => false, 'message' => 'Fichier introuvable. Générez d\'abord une miniature.']);
        }

        try {
            $this->youtubeData->uploadThumbnail($user, $youtubeId, $filePath);
        } catch (\Google\Service\Exception $e) {
            $this->logger->error('YouTube thumbnail upload failed', ['error' => $e->getMessage(), 'youtubeId' => $youtubeId]);
            if ($e->getCode() === 403) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Accès refusé par YouTube (403). Reconnectez votre compte Google pour mettre à jour les permissions.',
                    'reauth'  => true,
                ]);
            }
            return new JsonResponse(['success' => false, 'message' => 'Erreur YouTube : ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('YouTube thumbnail upload failed', ['error' => $e->getMessage(), 'youtubeId' => $youtubeId]);
            return new JsonResponse(['success' => false, 'message' => 'Erreur upload YouTube : ' . $e->getMessage()]);
        }

        $url = '/uploads/thumbnails/' . $filename;
        $video->setThumbnailUrl($url);
        $change = new ThumbnailChange($video, $video->getThumbnailUrl(), $url);
        $this->em->persist($change);
        $this->em->flush();

        return new JsonResponse(['success' => true, 'url' => $url, 'message' => 'Miniature appliquée sur YouTube avec succès.']);
    }

    #[Route('/videos/{youtubeId}/generated-thumbnails', name: 'analytics_video_generated_thumbnails', methods: ['GET'])]
    public function generatedThumbnails(string $youtubeId): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse([], 404);
        }

        $dir   = $this->projectDir . '/public/uploads/thumbnails/';
        $files = array_merge(
            glob($dir . $youtubeId . '_gen_*.jpg') ?: [],
            glob($dir . $youtubeId . '_gen_*.png') ?: [],
        );

        $results = [];
        foreach ($files as $path) {
            $name = basename($path);
            $results[] = [
                'filename' => $name,
                'url'      => '/uploads/thumbnails/' . $name,
                'created'  => filemtime($path),
            ];
        }

        // Most recent first
        usort($results, fn($a, $b) => $b['created'] <=> $a['created']);

        return new JsonResponse($results);
    }

    #[Route('/videos/{youtubeId}/delete-generated-thumbnail', name: 'analytics_video_delete_thumbnail', methods: ['POST'])]
    public function deleteGeneratedThumbnail(string $youtubeId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['success' => false], 404);
        }

        $filename = basename((string) $request->request->get('filename', ''));

        if (!str_starts_with($filename, $youtubeId . '_gen_') || (!str_ends_with($filename, '.png') && !str_ends_with($filename, '.jpg'))) {
            return new JsonResponse(['success' => false, 'message' => 'Fichier invalide.']);
        }

        $path = $this->projectDir . '/public/uploads/thumbnails/' . $filename;
        if (file_exists($path)) {
            unlink($path);
        }

        return new JsonResponse(['success' => true]);
    }

    #[Route('/videos/{youtubeId}/trigger-analysis', name: 'analytics_video_trigger_analysis', methods: ['POST'])]
    public function triggerAnalysis(string $youtubeId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['success' => false, 'message' => 'Vidéo introuvable.'], 404);
        }

        $type     = $request->request->get('type'); // null = all types
        $force    = (bool) $request->request->get('force', false);
        $jobId    = bin2hex(random_bytes(8));
        $cacheKey = 'job_' . $jobId;

        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(300);
            return ['status' => 'pending'];
        });

        try {
            $this->bus->dispatch(new RunAiAnalysisMessage($user->getId(), $jobId, $youtubeId, $type, $force));
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'messenger_messages') || str_contains($msg, "doesn't exist") || str_contains($msg, 'Base table')) {
                $msg = 'Table Messenger manquante. Lancez : php bin/console doctrine:migrations:migrate';
            }
            return new JsonResponse(['success' => false, 'message' => $msg]);
        }

        return new JsonResponse(['success' => true, 'jobId' => $jobId]);
    }

    #[Route('/videos/{youtubeId}/analysis-status/{jobId}', name: 'analytics_video_analysis_status', methods: ['GET'])]
    public function analysisStatus(string $youtubeId, string $jobId): JsonResponse
    {
        /** @var User $user */
        $user  = $this->getUser();
        $video = $this->videoRepo->findByYoutubeId($youtubeId);

        if (!$video || $video->getUser() !== $user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Vidéo introuvable.'], 404);
        }

        $result = $this->cache->get('job_' . $jobId, function (ItemInterface $item) {
            $item->expiresAfter(0);
            return null;
        });

        if ($result === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Job introuvable ou expiré.']);
        }

        return new JsonResponse($result);
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

        $metrics           = $this->metricRepo->findForVideo($video, 30);
        $retention         = $this->retentionRepo->findLatestForVideo($video);
        $comments          = $this->commentRepo->findNewForVideo($video);
        $titleReport       = $this->aiReportRepo->findRecentDone($video, AiReportType::TitleOptimization, 168);
        $commentReport     = $this->aiReportRepo->findRecentDone($video, AiReportType::CommentAnalysis, 168);
        $anomalyReport     = $this->aiReportRepo->findRecentDone($video, AiReportType::Anomaly, 168);
        $prediction        = $this->aiReportRepo->findRecentDone($video, AiReportType::Prediction, 720);
        $seoReport         = $this->aiReportRepo->findRecentDone($video, AiReportType::SeoOptimization, 168);
        $thumbnailReport   = $this->aiReportRepo->findRecentDone($video, AiReportType::ThumbnailAnalysis, 720);
        $descriptionReport = $this->aiReportRepo->findRecentDone($video, AiReportType::DescriptionOptimization, 720);
        $searchTerms       = $this->searchTermRepo->findTopForVideo($video, 20);
        $snapshots         = $this->snapshotRepo->findAllForVideo($video);
        $metaHistory       = $this->buildMetaHistory($snapshots, $metrics);

        // Aggregate traffic sources over last 30 days
        $trafficSourcesAgg = [];
        foreach ($metrics as $m) {
            foreach ($m->getTrafficSources() ?? [] as $source => $views) {
                $trafficSourcesAgg[$source] = ($trafficSourcesAgg[$source] ?? 0) + $views;
            }
        }
        arsort($trafficSourcesAgg);
        $trafficSourceLabels = [
            'YT_SEARCH'          => 'Recherche YouTube',
            'SUGGESTED_VIDEO'    => 'Suggestions',
            'EXTERNAL'           => 'Externe',
            'NO_LINK_EMBEDDED'   => 'Intégré',
            'DIRECT_OR_UNKNOWN'  => 'Direct',
            'PLAYLIST'           => 'Playlist',
            'YT_CHANNEL'         => 'Page chaîne',
            'YT_OTHER_PAGE'      => 'Autre page YT',
            'NOTIFICATION'       => 'Notification',
            'END_SCREEN'         => 'Écran de fin',
            'SHORTS'             => 'Shorts',
        ];
        $trafficTotal = array_sum($trafficSourcesAgg);

        // Estimated revenue
        $totalViews30d    = array_sum(array_column(array_map(fn($m) => ['v' => $m->getViews()], $metrics), 'v'));
        $estimatedRevenue = $totalViews30d * $user->getEstimatedRpm() / 1000;

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
            'video'                => $video,
            'metrics'              => $metrics,
            'latest_metric'        => !empty($metrics) ? end($metrics) : null,
            'chart_data'           => $chartData,
            'retention_data'       => $retentionData,
            'comments'             => $comments,
            'title_report'         => $titleReport,
            'comment_report'       => $commentReport,
            'anomaly_report'       => $anomalyReport,
            'prediction'           => $prediction,
            'seo_report'           => $seoReport,
            'thumbnail_report'     => $thumbnailReport,
            'description_report'   => $descriptionReport,
            'search_terms'         => $searchTerms,
            'meta_history'         => $metaHistory,
            'traffic_sources_agg'  => $trafficSourcesAgg,
            'traffic_source_labels' => $trafficSourceLabels,
            'traffic_total'        => $trafficTotal,
            'estimated_revenue'    => $estimatedRevenue,
            'estimated_rpm'        => $user->getEstimatedRpm(),
            'thumbnail_model_id'   => $this->settingRepo->get(GeminiService::SETTING_THUMBNAIL_MODEL) ?? 'imagen-3.0-generate-001',
            'thumbnail_model_name' => $this->resolveThumbnailModelName(),
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
            $maxViews = max(array_column($history, 'avg_views_day'));
            foreach ($history as $i => $period) {
                if ($period['avg_views_day'] === $maxViews) {
                    $history[$i]['is_best'] = true;
                    break;
                }
            }
        }

        return array_reverse($history); // most recent first
    }

    #[Route('/alerts', name: 'analytics_alerts')]
    public function alerts(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $reports = $this->aiReportRepo->findForUser($user, 300);
        $done    = array_filter($reports, fn($r) => $r->getStatus()->value === 'done');

        $urgent  = array_values(array_filter($done, fn($r) => $r->getType() === AiReportType::Anomaly));
        $conseil = array_values(array_filter($done, fn($r) => in_array($r->getType(), [
            AiReportType::TitleOptimization,
            AiReportType::SeoOptimization,
            AiReportType::CommentAnalysis,
            AiReportType::Prediction,
        ]) && $r->getVideo() !== null));

        return $this->render('analytics/alerts.html.twig', [
            'urgent'  => $urgent,
            'conseil' => $conseil,
            'total'   => count($urgent) + count($conseil),
        ]);
    }

    #[Route('/ai-costs', name: 'analytics_ai_costs')]
    public function aiCosts(Request $request): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $reports = $this->aiReportRepo->findForUser($user, 500);
        $monthly = $this->aiReportRepo->getMonthlyStats($user);

        // Pricing per model ($/M tokens)
        $pricing = [
            // Claude
            'claude-haiku-4-5-20251001'  => ['input' => 0.80,  'output' => 4.0],
            'claude-haiku-4-5'           => ['input' => 0.80,  'output' => 4.0],
            'claude-3-5-haiku-20241022'  => ['input' => 0.80,  'output' => 4.0],
            'claude-sonnet-4-6'          => ['input' => 3.0,   'output' => 15.0],
            'claude-sonnet-4-20250514'   => ['input' => 3.0,   'output' => 15.0],
            'claude-3-5-sonnet-20241022' => ['input' => 3.0,   'output' => 15.0],
            'claude-opus-4-5'            => ['input' => 15.0,  'output' => 75.0],
            'claude-opus-4-7'            => ['input' => 15.0,  'output' => 75.0],
            // Gemini
            'gemini-1.5-flash'           => ['input' => 0.075, 'output' => 0.30],
            'gemini-1.5-flash-8b'        => ['input' => 0.0375,'output' => 0.15],
            'gemini-1.5-pro'             => ['input' => 1.25,  'output' => 5.0],
            'gemini-2.0-flash'           => ['input' => 0.10,  'output' => 0.40],
            'gemini-2.0-flash-lite'      => ['input' => 0.075, 'output' => 0.30],
        ];
        $defaultPricing = ['input' => 1.0, 'output' => 4.0];

        // Per-image pricing (model → USD/image) for image generation models
        $imagePricing = [
            'imagen-3.0-generate-001'      => 0.04,
            'imagen-3.0-fast-generate-001' => 0.02,
        ];

        $byModel  = $this->aiReportRepo->getMonthlyStatsByModel($user);
        $costUsd  = 0.0;
        foreach ($byModel as $row) {
            $model = $row['model'] ?? '';
            if (isset($imagePricing[$model])) {
                // tokensInput = 1 image generated (stored by GenerateThumbnailHandler)
                $costUsd += ($row['tokens_input'] ?? 0) * $imagePricing[$model];
            } else {
                $p        = $pricing[$model] ?? $defaultPricing;
                $costUsd += ($row['tokens_input'] / 1_000_000 * $p['input'])
                          + ($row['tokens_output'] / 1_000_000 * $p['output']);
            }
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
                $model = $row['model'] ?? '';
                if (isset($imagePricing[$model])) {
                    $forecastUsd += ($row['tokens_input'] ?? 0) * $imagePricing[$model];
                } else {
                    $p             = $pricing[$model] ?? $defaultPricing;
                    $forecastUsd  += ($row['tokens_input'] / 1_000_000 * $p['input'])
                                   + ($row['tokens_output'] / 1_000_000 * $p['output']);
                }
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
