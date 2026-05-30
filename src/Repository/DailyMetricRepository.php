<?php
namespace App\Repository;

use App\Entity\DailyMetric;
use App\Entity\User;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<DailyMetric>
 */
class DailyMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheInterface $cache)
    {
        parent::__construct($registry, DailyMetric::class);
    }

    public function findForVideo(Video $video, int $days = 30): array
    {
        $since = new \DateTimeImmutable("-{$days} days");
        return $this->createQueryBuilder('dm')
            ->where('dm.video = :video')
            ->andWhere('dm.date >= :since')
            ->setParameter('video', $video)
            ->setParameter('since', $since)
            ->orderBy('dm.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForVideo(Video $video): ?DailyMetric
    {
        return $this->createQueryBuilder('dm')
            ->where('dm.video = :video')
            ->setParameter('video', $video)
            ->orderBy('dm.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Aggregate totals for a user over a period */
    public function getGlobalStatsForUser(User $user, int $days = 30): array
    {
        return $this->createQueryBuilder('dm')
            ->select('SUM(dm.views) as total_views, SUM(dm.watchTimeMinutes) as total_watch_time, SUM(dm.subscribersGained) as total_subscribers, AVG(dm.ctr) as avg_ctr')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', new \DateTimeImmutable("-{$days} days"))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Returns daily rows for chart: [date, views, watchTime, subscribers] */
    public function getDailyChartDataForUser(User $user, int $days = 30): array
    {
        return $this->createQueryBuilder('dm')
            ->select('dm.date, SUM(dm.views) as views, SUM(dm.watchTimeMinutes) as watchTime, SUM(dm.subscribersGained) as subscribers')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', new \DateTimeImmutable("-{$days} days"))
            ->groupBy('dm.date')
            ->orderBy('dm.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns per-video aggregated stats for the video list page in a single query.
     * Result: [videoId => ['total_views' => int, 'avg_ctr' => float|null, 'total_watch_time' => int]]
     */
    public function getListStatsForUser(User $user): array
    {
        return $this->cache->get('daily_list_stats_' . $user->getId(), function (ItemInterface $item) use ($user) {
            $item->expiresAfter(14400); // 4h
            $rows = $this->createQueryBuilder('dm')
                ->select('IDENTITY(dm.video) as video_id, SUM(dm.views) as total_views, AVG(dm.ctr) as avg_ctr, SUM(dm.watchTimeMinutes) as total_watch_time')
                ->join('dm.video', 'v')
                ->where('v.user = :user')
                ->setParameter('user', $user)
                ->groupBy('dm.video')
                ->getQuery()
                ->getArrayResult();

            $index = [];
            foreach ($rows as $row) {
                $index[(int) $row['video_id']] = [
                    'total_views'      => (int) $row['total_views'],
                    'avg_ctr'          => $row['avg_ctr'] !== null ? (float) $row['avg_ctr'] : null,
                    'total_watch_time' => (int) $row['total_watch_time'],
                ];
            }
            return $index;
        });
    }

    public function invalidateListStats(User $user): void
    {
        $this->cache->delete('daily_list_stats_' . $user->getId());
    }

    /** Get J+1, J+3, J+7 views for last N videos to compute anomaly baseline */
    public function getEarlyViewsBaseline(User $user, int $lastNVideos = 10): array
    {
        $videos = $this->getEntityManager()
            ->getRepository(\App\Entity\Video::class)
            ->createQueryBuilder('v')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->orderBy('v.publishedAt', 'DESC')
            ->setMaxResults($lastNVideos)
            ->getQuery()
            ->getResult();

        $baseline = [];
        foreach ($videos as $video) {
            $pub = $video->getPublishedAt();
            if (!$pub) continue;
            $row = ['video_id' => $video->getId(), 'j1' => 0, 'j3' => 0, 'j7' => 0];
            foreach ([1 => 'j1', 3 => 'j3', 7 => 'j7'] as $daysAfter => $key) {
                $targetDate = \DateTimeImmutable::createFromMutable((clone \DateTime::createFromImmutable($pub))->modify("+{$daysAfter} days"));
                $metric = $this->findOneBy(['video' => $video, 'date' => $targetDate]);
                $row[$key] = $metric ? $metric->getViews() : 0;
            }
            $baseline[] = $row;
        }
        return $baseline;
    }

    /**
     * Returns last N days of daily views per video for sparkline display.
     * Result: [videoId => [int, int, ...]] — always N values, 0 when no data.
     */
    public function getSparklineDataForUser(User $user, int $days = 7): array
    {
        $since = new \DateTimeImmutable("-{$days} days midnight");
        $rows  = $this->createQueryBuilder('dm')
            ->select('IDENTITY(dm.video) as video_id, dm.date, dm.views')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('dm.date', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = (new \DateTimeImmutable("-{$i} days"))->format('Y-m-d');
        }

        $indexed = [];
        foreach ($rows as $row) {
            $d = $row['date'] instanceof \DateTimeInterface ? $row['date']->format('Y-m-d') : (string) $row['date'];
            $indexed[(int)$row['video_id']][$d] = (int)$row['views'];
        }

        $result = [];
        foreach (array_keys($indexed) as $videoId) {
            foreach ($dates as $date) {
                $result[$videoId][] = $indexed[$videoId][$date] ?? 0;
            }
        }
        return $result;
    }

    /**
     * Returns total views in first 7 days after publication per video (for best-time analysis).
     * Result: [videoId => first_week_views]
     */
    public function getFirstWeekViewsByVideo(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql  = 'SELECT dm.video_id, SUM(dm.views) as first_week_views
                 FROM daily_metrics dm
                 JOIN videos v ON dm.video_id = v.id
                 WHERE v.user_id = :userId
                   AND v.published_at IS NOT NULL
                   AND dm.date BETWEEN DATE(v.published_at) AND DATE_ADD(DATE(v.published_at), INTERVAL 6 DAY)
                 GROUP BY dm.video_id';
        $rows   = $conn->fetchAllAssociative($sql, ['userId' => $user->getId()]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['video_id']] = (int)$row['first_week_views'];
        }
        return $result;
    }

    /**
     * Returns per-video daily views indexed by day-since-publication.
     * Result: [videoId => [['day' => int, 'views' => int], ...]]
     */
    public function getCompareDataForVideos(array $videos, int $maxDays = 365): array
    {
        if (empty($videos)) return [];

        $rows = $this->createQueryBuilder('dm')
            ->select('IDENTITY(dm.video) as video_id, dm.date, dm.views')
            ->where('dm.video IN (:videos)')
            ->setParameter('videos', $videos)
            ->orderBy('dm.date', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $origins = [];
        foreach ($videos as $video) {
            $pub = $video->getPublishedAt();
            if ($pub) {
                $origins[$video->getId()] = \DateTimeImmutable::createFromInterface($pub)->setTime(0, 0, 0);
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $videoId = (int) $row['video_id'];
            $date    = $row['date'] instanceof \DateTimeInterface
                ? \DateTimeImmutable::createFromInterface($row['date'])->setTime(0, 0, 0)
                : new \DateTimeImmutable((string) $row['date']);

            if (!isset($origins[$videoId])) {
                continue;
            }
            $day = (int) $origins[$videoId]->diff($date)->days;
            if ($day <= $maxDays) {
                $result[$videoId][] = ['day' => $day, 'views' => (int) $row['views']];
            }
        }
        return $result;
    }

    /** Returns total views/watch time/subscribers for a date range. */
    public function getTotalsForRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('dm')
            ->select('SUM(dm.views) as views, SUM(dm.watchTimeMinutes) as watch_time, SUM(dm.subscribersGained) as subscribers, AVG(dm.ctr) as avg_ctr')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date >= :from')
            ->andWhere('dm.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getOneOrNullResult() ?? [];
    }

    /** Returns top N videos (by total views) for a date range. */
    public function getTopVideosForRange(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 5): array
    {
        $rows = $this->createQueryBuilder('dm')
            ->select('IDENTITY(dm.video) as video_id, SUM(dm.views) as total_views')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date >= :from')
            ->andWhere('dm.date <= :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('dm.video')
            ->orderBy('total_views', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        // Resolve Video entities
        $em     = $this->getEntityManager();
        $result = [];
        foreach ($rows as $row) {
            $video = $em->find(\App\Entity\Video::class, (int)$row['video_id']);
            if ($video) {
                $result[] = ['video' => $video, 'total_views' => (int)$row['total_views']];
            }
        }
        return $result;
    }

    /** Returns the most recent date that has metrics for a user. */
    public function getLatestDateWithData(User $user): ?\DateTimeImmutable
    {
        $row = $this->createQueryBuilder('dm')
            ->select('MAX(dm.date) as latest')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();

        return $row['latest'] ? new \DateTimeImmutable($row['latest']) : null;
    }

    /** Returns aggregated totals for a specific date. */
    public function getTotalsForDate(User $user, \DateTimeImmutable $date): array
    {
        return $this->createQueryBuilder('dm')
            ->select('
                SUM(dm.views)              as views,
                SUM(dm.watchTimeMinutes)   as watch_time,
                SUM(dm.subscribersGained)  as subscribers,
                SUM(dm.impressions)        as impressions,
                AVG(dm.ctr)                as avg_ctr
            ')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date)
            ->getQuery()
            ->getOneOrNullResult() ?? [];
    }

    /** Returns top N videos by views for a specific date. */
    public function getTopVideosForDate(User $user, \DateTimeImmutable $date, int $limit = 8): array
    {
        return $this->createQueryBuilder('dm')
            ->select('dm, v')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date = :date')
            ->andWhere('dm.views > 0')
            ->setParameter('user', $user)
            ->setParameter('date', $date)
            ->orderBy('dm.views', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns per-video momentum: views last 3 days vs previous 3 days.
     * Result: [videoId => ['recent' => int, 'prev' => int, 'pct' => int]]
     */
    public function getTrendDataForUser(User $user): array
    {
        $since = new \DateTimeImmutable('-6 days midnight');
        $rows  = $this->createQueryBuilder('dm')
            ->select('IDENTITY(dm.video) as video_id, dm.date, dm.views')
            ->join('dm.video', 'v')
            ->where('v.user = :user')
            ->andWhere('dm.date >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getArrayResult();

        $byVideo = [];
        foreach ($rows as $row) {
            $d = $row['date'] instanceof \DateTimeInterface
                ? $row['date']->format('Y-m-d')
                : (string) $row['date'];
            $byVideo[(int)$row['video_id']][$d] = (int)$row['views'];
        }

        $today  = new \DateTimeImmutable();
        $result = [];
        foreach ($byVideo as $videoId => $byDate) {
            $recent = 0;
            $prev   = 0;
            for ($i = 1; $i <= 3; $i++) {
                $recent += $byDate[$today->modify("-{$i} days")->format('Y-m-d')] ?? 0;
            }
            for ($i = 4; $i <= 6; $i++) {
                $prev += $byDate[$today->modify("-{$i} days")->format('Y-m-d')] ?? 0;
            }
            $pct = $prev > 0
                ? (int) round(($recent - $prev) / $prev * 100)
                : ($recent > 0 ? 100 : 0);
            $result[$videoId] = ['recent' => $recent, 'prev' => $prev, 'pct' => $pct];
        }
        return $result;
    }

    /**
     * Returns avg first-week views per (day-of-week × 3h slot) for the heatmap.
     * Result: [dow_iso => [hour_bucket => ['avg' => int, 'count' => int]]]
     * dow_iso: 1=Mon … 7=Sun, hour_bucket: 0 (0–3h) … 7 (21–24h)
     */
    public function getHeatmapDataForUser(User $user): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql  = 'SELECT
                     DAYOFWEEK(v.published_at)               AS dow_mysql,
                     FLOOR(HOUR(v.published_at) / 3)         AS hour_bucket,
                     AVG(fw.first_week_views)                AS avg_views,
                     COUNT(*)                                AS cnt
                 FROM videos v
                 JOIN (
                     SELECT dm2.video_id, SUM(dm2.views) AS first_week_views
                     FROM daily_metrics dm2
                     JOIN videos v2 ON dm2.video_id = v2.id
                     WHERE v2.user_id = :userId
                       AND v2.published_at IS NOT NULL
                       AND dm2.date BETWEEN DATE(v2.published_at)
                           AND DATE_ADD(DATE(v2.published_at), INTERVAL 6 DAY)
                     GROUP BY dm2.video_id
                 ) fw ON fw.video_id = v.id
                 WHERE v.user_id = :userId
                   AND v.published_at IS NOT NULL
                 GROUP BY dow_mysql, hour_bucket';

        $rows   = $conn->fetchAllAssociative($sql, ['userId' => $user->getId()]);
        $result = [];
        foreach ($rows as $row) {
            $iso = ((int)$row['dow_mysql'] + 5) % 7 + 1; // MySQL 1=Sun → ISO 1=Mon
            $result[$iso][(int)$row['hour_bucket']] = [
                'avg'   => (int) round((float)$row['avg_views']),
                'count' => (int) $row['cnt'],
            ];
        }
        return $result;
    }

    /** Deletes daily metrics older than $before. Returns number of deleted rows. */
    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        $userIds = $this->createQueryBuilder('dm')
            ->select('DISTINCT IDENTITY(v.user) as user_id')
            ->join('dm.video', 'v')
            ->where('dm.date < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleColumnResult();

        $deleted = (int) $this->createQueryBuilder('dm')
            ->delete()
            ->where('dm.date < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();

        foreach ($userIds as $userId) {
            $this->cache->delete('daily_list_stats_' . (int) $userId);
        }

        return $deleted;
    }
}
