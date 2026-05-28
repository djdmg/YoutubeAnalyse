<?php

namespace App\Service;

use App\Entity\ChannelStats;
use App\Entity\User;
use App\Entity\VideoStats;
use App\Repository\ChannelStatsRepository;
use App\Repository\GoogleTokenRepository;
use App\Repository\VideoStatsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Google\Service\YouTube;
use Google\Service\YouTubeAnalytics;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class YouTubeDataService
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly EntityManagerInterface $em,
        private readonly ChannelStatsRepository $channelStatsRepo,
        private readonly VideoStatsRepository $videoStatsRepo,
        private readonly GoogleTokenRepository $tokenRepo,
        private readonly CacheInterface $cache,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function syncAll(User $user): array
    {
        $client = $this->authService->getAuthenticatedClientForUser($user);
        if (!$client) {
            throw new \RuntimeException('Non authentifié avec Google. Veuillez connecter votre compte.');
        }

        $token = $this->tokenRepo->findForUser($user);
        if (!$token) {
            throw new \RuntimeException('Token Google introuvable pour cet utilisateur.');
        }
        $channelId = $token->getChannelId();
        $youtube   = new YouTube($client);
        $analytics = new YouTubeAnalytics($client);
        $syncTime  = new \DateTimeImmutable();

        $channelStats = $this->syncChannelStats($youtube, $analytics, $channelId, $user, $syncTime);
        $videoCount   = $this->syncVideoStats($youtube, $analytics, $channelId, $user, $syncTime);

        // Invalidate dashboard chart cache so next load fetches fresh data
        $this->cache->delete('yt_daily_analytics_' . $user->getId() . '_30');

        return [
            'channel'       => $channelStats->getChannelTitle(),
            'videos_synced' => $videoCount,
            'subscribers'   => $channelStats->getSubscriberCount(),
            'views'         => $channelStats->getViewCount(),
        ];
    }

    private function syncChannelStats(YouTube $youtube, YouTubeAnalytics $analytics, string $channelId, User $user, \DateTimeImmutable $syncTime): ChannelStats
    {
        $response = $youtube->channels->listChannels('snippet,statistics', ['mine' => true]);
        $channel  = $response->getItems()[0];
        $stats    = $channel->getStatistics();

        $analyticsData = $this->fetchChannelAnalytics($analytics, $channelId);

        $channelStats = new ChannelStats();
        $channelStats->setUser($user)
            ->setChannelId($channel->getId())
            ->setChannelTitle($channel->getSnippet()->getTitle())
            ->setViewCount((int) $stats->getViewCount())
            ->setSubscriberCount((int) $stats->getSubscriberCount())
            ->setVideoCount((int) $stats->getVideoCount())
            ->setWatchTimeMinutes($analyticsData['watchTimeMinutes'] ?? null)
            ->setAverageViewDuration($analyticsData['averageViewDuration'] ?? null)
            ->setLikes($analyticsData['likes'] ?? null)
            ->setComments($analyticsData['comments'] ?? null)
            ->setShares($analyticsData['shares'] ?? null)
            ->setRecordedAt($syncTime);

        $this->em->persist($channelStats);
        $this->em->flush();

        return $channelStats;
    }

    private function fetchChannelAnalytics(YouTubeAnalytics $analytics, string $channelId): array
    {
        $result        = [];
        $today         = (new \DateTimeImmutable())->format('Y-m-d');
        $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->format('Y-m-d');

        try {
            $response = $analytics->reports->query([
                'ids'       => "channel=={$channelId}",
                'startDate' => $thirtyDaysAgo,
                'endDate'   => $today,
                'metrics'   => 'estimatedMinutesWatched,averageViewDuration,likes,comments,shares',
            ]);

            if ($rows = $response->getRows()) {
                $row = $rows[0];
                $result['watchTimeMinutes']  = (int) ($row[0] ?? 0);
                $result['averageViewDuration'] = $row[1] ?? null;
                $result['likes']             = (int) ($row[2] ?? 0);
                $result['comments']          = (int) ($row[3] ?? 0);
                $result['shares']            = (int) ($row[4] ?? 0);
            }
        } catch (\Exception) {}

        return $result;
    }

    private function syncVideoStats(YouTube $youtube, YouTubeAnalytics $analytics, string $channelId, User $user, \DateTimeImmutable $syncTime): int
    {
        $videoIds = $this->getAllVideoIds($youtube, $channelId);
        if (empty($videoIds)) {
            return 0;
        }

        $count = 0;
        foreach (array_chunk($videoIds, 50) as $chunk) {
            $response = $youtube->videos->listVideos(
                'id,snippet,statistics,contentDetails',
                ['id' => implode(',', $chunk)]
            );

            foreach ($response->getItems() as $video) {
                $stats   = $video->getStatistics();
                $snippet = $video->getSnippet();
                $details = $video->getContentDetails();

                $analyticsData = $this->fetchVideoAnalytics($analytics, $channelId, $video->getId());

                $videoStats = new VideoStats();
                $videoStats->setUser($user)
                    ->setVideoId($video->getId())
                    ->setChannelId($channelId)
                    ->setTitle($snippet->getTitle())
                    ->setDescription(substr($snippet->getDescription() ?? '', 0, 1000))
                    ->setThumbnailUrl($snippet->getThumbnails()?->getMedium()?->getUrl())
                    ->setPublishedAt(new \DateTimeImmutable($snippet->getPublishedAt()))
                    ->setViewCount((int) $stats->getViewCount())
                    ->setLikeCount((int) $stats->getLikeCount())
                    ->setCommentCount((int) $stats->getCommentCount())
                    ->setDuration($details->getDuration())
                    ->setWatchTimeMinutes($analyticsData['watchTimeMinutes'] ?? null)
                    ->setAverageViewPercentage($analyticsData['averageViewPercentage'] ?? null)
                    ->setSubscribersGained($analyticsData['subscribersGained'] ?? null)
                    ->setRecordedAt($syncTime);

                $this->em->persist($videoStats);
                $count++;
            }
        }

        $this->em->flush();
        return $count;
    }

    private function getAllVideoIds(YouTube $youtube, string $channelId): array
    {
        $ids       = [];
        $pageToken = null;

        do {
            $params = ['channelId' => $channelId, 'maxResults' => 50, 'type' => 'video', 'order' => 'date'];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $youtube->search->listSearch('id', $params);
            foreach ($response->getItems() as $item) {
                $ids[] = $item->getId()->getVideoId();
            }
            $pageToken = $response->getNextPageToken();
        } while ($pageToken && count($ids) < 200);

        return $ids;
    }

    private function fetchVideoAnalytics(YouTubeAnalytics $analytics, string $channelId, string $videoId): array
    {
        $result        = [];
        $today         = (new \DateTimeImmutable())->format('Y-m-d');
        $ninetyDaysAgo = (new \DateTimeImmutable('-90 days'))->format('Y-m-d');

        try {
            $response = $analytics->reports->query([
                'ids'       => "channel=={$channelId}",
                'startDate' => $ninetyDaysAgo,
                'endDate'   => $today,
                'metrics'   => 'estimatedMinutesWatched,averageViewPercentage,subscribersGained',
                'filters'   => "video=={$videoId}",
            ]);

            if ($rows = $response->getRows()) {
                $row = $rows[0];
                $result['watchTimeMinutes']      = (int) ($row[0] ?? 0);
                $result['averageViewPercentage'] = $row[1] ?? null;
                $result['subscribersGained']     = (int) ($row[2] ?? 0);
            }
        } catch (\Exception) {}

        return $result;
    }

    public function uploadThumbnail(User $user, string $youtubeId, string $filePath): void
    {
        $client = $this->authService->getAuthenticatedClientForUser($user);
        if (!$client) {
            throw new \RuntimeException('Non authentifié avec Google.');
        }

        $mimeType  = mime_content_type($filePath) ?: 'image/png';
        $chunkSize = 1 * 1024 * 1024; // 1 MB

        $youtube = new YouTube($client);

        $client->setDefer(true);
        $setRequest = $youtube->thumbnails->set($youtubeId);
        $client->setDefer(false);

        $media = new \Google\Http\MediaFileUpload(
            $client,
            $setRequest,
            $mimeType,
            null,
            true,
            $chunkSize
        );
        $media->setFileSize(filesize($filePath));

        $status = false;
        $handle = fopen($filePath, 'rb');
        try {
            while (!$status && !feof($handle)) {
                $chunk  = fread($handle, $chunkSize);
                $status = $media->nextChunk($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    public function getDailyAnalytics(User $user, int $days = 30): array
    {
        $cacheKey = 'yt_daily_analytics_' . $user->getId() . '_' . $days;
        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($user, $days) {
            $item->expiresAfter(21600); // 6h — YouTube Analytics data lags 1-2 days anyway

            $client = $this->authService->getAuthenticatedClientForUser($user);
            if (!$client) return [];

            $token     = $this->tokenRepo->findForUser($user);
            $analytics = new YouTubeAnalytics($client);
            $today     = (new \DateTimeImmutable())->format('Y-m-d');
            $from      = (new \DateTimeImmutable("-{$days} days"))->format('Y-m-d');

            foreach ([
                'views,estimatedMinutesWatched,subscribersGained',
                'views,estimatedMinutesWatched',
            ] as $metrics) {
                try {
                    $response = $analytics->reports->query([
                        'ids'        => "channel=={$token->getChannelId()}",
                        'startDate'  => $from,
                        'endDate'    => $today,
                        'metrics'    => $metrics,
                        'dimensions' => 'day',
                        'sort'       => 'day',
                    ]);
                    $rows = $response->getRows() ?? [];
                    if (!empty($rows)) {
                        $hasSubscribers = str_contains($metrics, 'subscribersGained');
                        return array_map(fn($row) => [
                            $row[0],
                            (int) ($row[1] ?? 0),
                            (int) ($row[2] ?? 0),
                            $hasSubscribers ? (int)($row[3] ?? 0) : 0,
                        ], $rows);
                    }
                } catch (\Exception $e) {
                    error_log('[YouTubeAnalytics] metrics="'.$metrics.'" error: '.$e->getMessage());
                }
            }
            return [];
        });
    }
}
