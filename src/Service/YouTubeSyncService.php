<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\ChannelStats;
use App\Entity\DailyMetric;
use App\Entity\RetentionPoint;
use App\Entity\User;
use App\Entity\Video;
use App\Entity\VideoStats;
use App\Entity\VideoMetaSnapshot;
use App\Entity\VideoSearchTerm;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\GoogleTokenRepository;
use App\Repository\RetentionPointRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoMetaSnapshotRepository;
use App\Repository\VideoSearchTermRepository;
use Doctrine\ORM\EntityManagerInterface;
use Google\Service\YouTube;
use Google\Service\YouTubeAnalytics;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class YouTubeSyncService
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly GoogleTokenRepository $tokenRepo,
        private readonly QuotaGuardService $quotaGuard,
        private readonly EntityManagerInterface $em,
        private readonly VideoRepository $videoRepo,
        private readonly DailyMetricRepository $dailyMetricRepo,
        private readonly RetentionPointRepository $retentionRepo,
        private readonly CommentRepository $commentRepo,
        private readonly VideoMetaSnapshotRepository $snapshotRepo,
        private readonly VideoSearchTermRepository $searchTermRepo,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        private readonly ?YouTubeReportingService $reportingService = null,
    ) {}

    public function syncForUser(User $user): array
    {
        $client = $this->authService->getAuthenticatedClientForUser($user);
        if (!$client) {
            throw new \RuntimeException('Utilisateur non authentifié avec Google.');
        }

        $token     = $this->tokenRepo->findForUser($user);
        if (!$token) {
            throw new \RuntimeException('Token Google introuvable pour cet utilisateur.');
        }

        $channelId = $token->getChannelId();
        $youtube   = new YouTube($client);
        $analytics = new YouTubeAnalytics($client);
        $today     = new \DateTimeImmutable();

        $channelStats     = $this->syncChannelStatsSnapshot($youtube, $channelId, $user, $today);
        $videoSyncCounts  = $this->syncVideos($youtube, $analytics, $channelId, $user, $today);
        $commentsCount    = $this->syncComments($youtube, $channelId, $user);
        $searchTermsCount = $this->syncSearchTerms($analytics, $channelId, $user, $today);

        $reportingCounts = ['impressions_ctr' => 0, 'demographics' => 0, 'traffic_sources' => 0];
        if ($this->reportingService) {
            try {
                $reportingCounts = $this->reportingService->syncForUser($user);
            } catch (\Exception $e) {
                $this->logger->warning('Reporting API sync failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'videos_synced'           => $videoSyncCounts['videos'],
            'daily_metrics_synced'    => $videoSyncCounts['daily_metrics'],
            'comments_synced'         => $commentsCount,
            'search_terms_synced'     => $searchTermsCount,
            'channel_id'              => $channelId,
            'channel'                 => $channelStats->getChannelTitle(),
            'subscribers'             => $channelStats->getSubscriberCount(),
            'views'                   => $channelStats->getViewCount(),
            'impressions_ctr_updated' => $reportingCounts['impressions_ctr'],
            'demographics_updated'    => $reportingCounts['demographics'],
            'traffic_sources_updated' => $reportingCounts['traffic_sources'],
        ];
    }

    private function syncChannelStatsSnapshot(YouTube $youtube, string $channelId, User $user, \DateTimeImmutable $syncTime): ChannelStats
    {
        $this->quotaGuard->assertQuota(1);
        $response = $youtube->channels->listChannels('snippet,statistics', ['id' => $channelId]);
        $this->quotaGuard->consume(1);

        $channel = $response->getItems()[0] ?? null;
        if (!$channel) {
            throw new \RuntimeException('Chaîne YouTube introuvable pour ce token.');
        }

        $stats = $channel->getStatistics();
        $snapshot = (new ChannelStats())
            ->setUser($user)
            ->setChannelId($channel->getId())
            ->setChannelTitle($channel->getSnippet()->getTitle())
            ->setViewCount((int) $stats->getViewCount())
            ->setSubscriberCount((int) $stats->getSubscriberCount())
            ->setVideoCount((int) $stats->getVideoCount())
            ->setRecordedAt($syncTime);

        $this->em->persist($snapshot);
        $this->em->flush();

        foreach ([30, 90] as $days) {
            $this->cache->delete('yt_daily_analytics_' . $user->getId() . '_' . $days);
        }

        return $snapshot;
    }

    /** @return array{videos: int, daily_metrics: int} */
    private function syncVideos(YouTube $youtube, YouTubeAnalytics $analytics, string $channelId, User $user, \DateTimeImmutable $today): array
    {
        $this->quotaGuard->assertQuota(100);
        $videoIds = $this->getAllVideoIds($youtube, $channelId);
        if (empty($videoIds)) return ['videos' => 0, 'daily_metrics' => 0];

        $count        = 0;
        $metricsCount = 0;
        foreach (array_chunk($videoIds, 50) as $chunk) {
            $this->quotaGuard->assertQuota(1);
            $response = $youtube->videos->listVideos('id,snippet,statistics,contentDetails', ['id' => implode(',', $chunk)]);
            $this->quotaGuard->consume(1);

            foreach ($response->getItems() as $item) {
                $video = $this->videoRepo->findByYoutubeId($item->getId())
                    ?? new Video();

                $snippet = $item->getSnippet();
                $stats   = $item->getStatistics();
                $details = $item->getContentDetails();

                $newTitle       = $this->sanitizeText($snippet->getTitle()) ?? '';
                $newDescription = $this->sanitizeText(mb_substr($snippet->getDescription() ?? '', 0, 2000));

                // Snapshot before overwriting — detect title/description changes
                $this->recordSnapshotIfChanged($video, $newTitle, $newDescription);

                $video->setUser($user)
                    ->setYoutubeId($item->getId())
                    ->setChannelId($channelId)
                    ->setTitle($newTitle)
                    ->setDescription($newDescription)
                    ->setThumbnailUrl($snippet->getThumbnails()?->getMedium()?->getUrl())
                    ->setPublishedAt(new \DateTimeImmutable($snippet->getPublishedAt()))
                    ->setDurationSeconds($this->isoDurationToSeconds($details->getDuration()));

                $this->em->persist($video);
                $this->persistVideoStatsSnapshot($item, $channelId, $user, $today);
                $this->em->flush();

                $metricsCount += $this->syncDailyMetric($analytics, $channelId, $video, $today);
                $count++;
            }
        }

        if ($metricsCount > 0) {
            $this->dailyMetricRepo->invalidateListStats($user);
        }

        return ['videos' => $count, 'daily_metrics' => $metricsCount];
    }

    private function persistVideoStatsSnapshot(mixed $item, string $channelId, User $user, \DateTimeImmutable $syncTime): void
    {
        $snippet = $item->getSnippet();
        $stats   = $item->getStatistics();
        $details = $item->getContentDetails();

        $snapshot = (new VideoStats())
            ->setUser($user)
            ->setVideoId($item->getId())
            ->setChannelId($channelId)
            ->setTitle($this->sanitizeText($snippet->getTitle()) ?? '')
            ->setDescription($this->sanitizeText(mb_substr($snippet->getDescription() ?? '', 0, 1000)))
            ->setThumbnailUrl($snippet->getThumbnails()?->getMedium()?->getUrl())
            ->setPublishedAt(new \DateTimeImmutable($snippet->getPublishedAt()))
            ->setViewCount((int) $stats->getViewCount())
            ->setLikeCount((int) $stats->getLikeCount())
            ->setCommentCount((int) $stats->getCommentCount())
            ->setDuration($details->getDuration())
            ->setRecordedAt($syncTime);

        $this->em->persist($snapshot);
    }

    private function syncDailyMetric(YouTubeAnalytics $analytics, string $channelId, Video $video, \DateTimeImmutable $today): int
    {
        // First sync: full history from publication date. Subsequent syncs: from last known date
        // (catches the 2-day Analytics API delay and any missed days)
        $latestMetric = $this->dailyMetricRepo->findLatestForVideo($video);
        if ($latestMetric) {
            // Re-fetch from last known date (Analytics data may arrive late)
            $startDate = $latestMetric->getDate()->format('Y-m-d');
        } else {
            $startDate = ($video->getPublishedAt() ?? $today->modify('-90 days'))->format('Y-m-d');
        }
        $endDate = $today->modify('-1 day')->format('Y-m-d'); // Analytics has ~1-2 day delay

        try {
            $writtenRows = 0;
            $response = $analytics->reports->query([
                'ids'        => "channel=={$channelId}",
                'startDate'  => $startDate,
                'endDate'    => $endDate,
                'metrics'    => 'views,estimatedMinutesWatched,averageViewPercentage,subscribersGained',
                'dimensions' => 'day',
                'sort'       => 'day',
                'filters'    => "video=={$video->getYoutubeId()}",
            ]);

            foreach ($response->getRows() ?? [] as $row) {
                $date     = new \DateTimeImmutable($row[0]);
                $existing = $this->dailyMetricRepo->findOneBy(['video' => $video, 'date' => $date->setTime(0, 0, 0)])
                    ?? (new DailyMetric())->setVideo($video)->setDate($date->setTime(0, 0, 0));

                $existing->setViews((int)($row[1] ?? 0))
                    ->setWatchTimeMinutes((int)($row[2] ?? 0))
                    ->setAvgRetentionPercent($row[3] ? (float)$row[3] : null)
                    ->setSubscribersGained((int)($row[4] ?? 0));

                $this->em->persist($existing);
                $writtenRows++;
            }

            // Traffic sources for today only
            try {
                $trafficResponse = $analytics->reports->query([
                    'ids'        => "channel=={$channelId}",
                    'startDate'  => $endDate,
                    'endDate'    => $endDate,
                    'metrics'    => 'views',
                    'dimensions' => 'insightTrafficSourceType',
                    'filters'    => "video=={$video->getYoutubeId()}",
                ]);
                if ($trafficRows = $trafficResponse->getRows()) {
                    $sources    = [];
                    foreach ($trafficRows as $r) $sources[$r[0]] = (int)$r[1];
                    // Traffic is queried for $endDate (yesterday), not today
                    $endDateObj  = new \DateTimeImmutable($endDate);
                    $trafficMetric = $this->dailyMetricRepo->findOneBy(['video' => $video, 'date' => $endDateObj->setTime(0, 0, 0)]);
                    $trafficMetric?->setTrafficSources($sources);
                }
            } catch (\Exception) {}

        } catch (\Exception $e) {
            $this->logger->warning('DailyMetric sync failed', [
                'video_id' => $video->getYoutubeId(),
                'error'    => $e->getMessage(),
            ]);
        }

        $this->em->flush();
        return $writtenRows ?? 0;
    }

    private function syncComments(YouTube $youtube, string $channelId, User $user): int
    {
        $videos = $this->videoRepo->findForUser($user);
        $count  = 0;

        foreach ($videos as $video) {
            try {
                $this->quotaGuard->assertQuota(1);
                $response = $youtube->commentThreads->listCommentThreads('snippet', [
                    'videoId'    => $video->getYoutubeId(),
                    'maxResults' => 100,
                    'order'      => 'time',
                ]);
                $this->quotaGuard->consume(1);

                $syncedAt = new \DateTimeImmutable();
                foreach ($response->getItems() as $thread) {
                    $topComment = $thread->getSnippet()->getTopLevelComment();
                    $ytId       = $topComment->getId();

                    if ($this->commentRepo->findOneBy(['youtubeCommentId' => $ytId])) continue;

                    $c = new Comment();
                    $c->setVideo($video)
                        ->setYoutubeCommentId($ytId)
                        ->setText($this->sanitizeText($topComment->getSnippet()->getTextDisplay()) ?? '')
                        ->setPublishedAt(new \DateTimeImmutable($topComment->getSnippet()->getPublishedAt()))
                        ->setSyncedAt($syncedAt);

                    $this->em->persist($c);
                    $count++;
                }
                $this->em->flush();
            } catch (\Exception $e) {
                $this->logger->warning('Comment sync failed', [
                    'video_id' => $video->getYoutubeId(),
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $count;
    }

    private function recordSnapshotIfChanged(Video $video, string $newTitle, ?string $newDescription): void
    {
        if ($video->getId() === null) return; // new video — nothing to compare against

        $last = $this->snapshotRepo->findLatestForVideo($video);

        $titleChanged = $last === null || $last->getTitle() !== $newTitle;
        $descChanged  = $last !== null && $last->getDescription() !== $newDescription;

        if ($titleChanged || $descChanged) {
            $snapshot = (new VideoMetaSnapshot())
                ->setVideo($video)
                ->setTitle($newTitle)
                ->setDescription($newDescription)
                ->setRecordedAt(new \DateTimeImmutable());
            $this->em->persist($snapshot);
        }
    }

    /**
     * Syncs top search queries that led viewers to each video.
     * Uses insightTrafficSourceDetail with YT_SEARCH filter — 1 API unit per video.
     * Window: last 90 days (avoids timeouts on very old videos; Analytics data rarely
     * changes for content older than ~3 months anyway).
     */
    private function syncSearchTerms(YouTubeAnalytics $analytics, string $channelId, User $user, \DateTimeImmutable $today): int
    {
        $videos    = $this->videoRepo->findForUser($user);
        $endDate   = $today->modify('-1 day')->format('Y-m-d');
        // Use max 90 days to stay within API limits; clamp to publish date for newer videos
        $windowStart = $today->modify('-90 days');
        $now       = new \DateTimeImmutable();
        $synced    = 0;

        foreach ($videos as $video) {
            if (!$this->quotaGuard->hasQuota(1)) break;

            $publishedAt = $video->getPublishedAt();
            $startDate   = ($publishedAt && $publishedAt > $windowStart)
                ? $publishedAt->format('Y-m-d')
                : $windowStart->format('Y-m-d');

            try {
                $response = $analytics->reports->query([
                    'ids'        => "channel=={$channelId}",
                    'startDate'  => $startDate,
                    'endDate'    => $endDate,
                    'metrics'    => 'views',
                    'dimensions' => 'insightTrafficSourceDetail',
                    'filters'    => "video=={$video->getYoutubeId()};insightTrafficSourceType==YT_SEARCH",
                    'sort'       => '-views',
                    'maxResults' => 25,
                ]);
                $this->quotaGuard->consume(1);

                $rows = $response->getRows() ?? [];
                $this->logger->debug('Search terms API response', [
                    'video' => $video->getYoutubeId(),
                    'rows'  => count($rows),
                ]);

                foreach ($rows as $row) {
                    $query = trim((string) $row[0]);
                    $views = (int) ($row[1] ?? 0);
                    if ($query === '' || $views === 0) continue;

                    $term = $this->searchTermRepo->findByQuery($video, $query)
                        ?? (new VideoSearchTerm())->setVideo($video)->setQuery($query);

                    $term->setViews($views)->setSyncedAt($now);
                    $this->em->persist($term);
                    $synced++;
                }

                $this->em->flush();

            } catch (\Exception $e) {
                $this->logger->warning('Search terms sync failed', [
                    'video'      => $video->getYoutubeId(),
                    'startDate'  => $startDate,
                    'endDate'    => $endDate,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Search terms sync complete', ['synced' => $synced, 'channel' => $channelId]);
        return $synced;
    }

    private function getAllVideoIds(YouTube $youtube, string $channelId): array
    {
        $ids       = [];
        $pageToken = null;

        do {
            $this->quotaGuard->assertQuota(100);
            $params = ['channelId' => $channelId, 'maxResults' => 50, 'type' => 'video', 'order' => 'date'];
            if ($pageToken) $params['pageToken'] = $pageToken;

            $response = $youtube->search->listSearch('id', $params);
            $this->quotaGuard->consume(100);

            foreach ($response->getItems() as $item) {
                $ids[] = $item->getId()->getVideoId();
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken && count($ids) < 200);

        return $ids;
    }

    private function sanitizeText(?string $text): ?string
    {
        if ($text === null) return null;
        // Strip 4-byte UTF-8 characters (emojis, rare Unicode) incompatible with MySQL utf8 (non-mb4) columns
        return preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    private function isoDurationToSeconds(string $duration): ?int
    {
        if (!$duration) return null;
        try {
            $interval = new \DateInterval($duration);
            return ($interval->d * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (\Exception) {
            return null;
        }
    }
}
