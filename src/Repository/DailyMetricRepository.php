<?php
namespace App\Repository;

use App\Entity\DailyMetric;
use App\Entity\User;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyMetric>
 */
class DailyMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
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

        return $row['latest'] ?? null;
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
}
