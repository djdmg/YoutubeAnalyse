<?php
namespace App\Repository;

use App\Entity\AiReport;
use App\Entity\User;
use App\Entity\Video;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @extends ServiceEntityRepository<AiReport>
 */
class AiReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly CacheInterface $cache)
    {
        parent::__construct($registry, AiReport::class);
    }

    public function findRecentDone(?Video $video, AiReportType $type, int $withinHours = 24): ?AiReport
    {
        $since = new \DateTimeImmutable("-{$withinHours} hours");
        $qb = $this->createQueryBuilder('r')
            ->where('r.type = :type')
            ->andWhere('r.status = :status')
            ->andWhere('r.generatedAt >= :since')
            ->setParameter('type', $type)
            ->setParameter('status', AiReportStatus::Done)
            ->setParameter('since', $since)
            ->orderBy('r.generatedAt', 'DESC')
            ->setMaxResults(1);

        if ($video) {
            $qb->andWhere('r.video = :video')->setParameter('video', $video);
        } else {
            $qb->andWhere('r.video IS NULL');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /** Returns done reports generated at or after $since for a user. */
    public function findGeneratedSince(User $user, \DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.video', 'v')
            ->where('v.user = :user OR r.video IS NULL')
            ->andWhere('r.generatedAt >= :since')
            ->andWhere('r.status = :done')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->setParameter('done', AiReportStatus::Done)
            ->orderBy('r.generatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findForUser(User $user, int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.video', 'v')
            ->where('v.user = :user OR r.video IS NULL')
            ->setParameter('user', $user)
            ->orderBy('r.generatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getMonthlyStats(User $user): array
    {
        $month = (new \DateTimeImmutable())->format('Y-m');
        return $this->cache->get('ai_monthly_stats_' . $user->getId() . '_' . $month, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(7200); // 2h
            $since = new \DateTimeImmutable('first day of this month 00:00:00');
            return $this->createQueryBuilder('r')
                ->select('SUM(r.tokensInput) as tokens_input, SUM(r.tokensOutput) as tokens_output, COUNT(r.id) as total_calls, SUM(CASE WHEN r.status = \'done\' THEN 1 ELSE 0 END) as success_calls')
                ->leftJoin('r.video', 'v')
                ->where('v.user = :user OR r.video IS NULL')
                ->andWhere('r.generatedAt >= :since')
                ->setParameter('user', $user)
                ->setParameter('since', $since)
                ->getQuery()
                ->getOneOrNullResult() ?? [];
        });
    }

    /** Returns token usage grouped by model version for the previous calendar month. */
    public function getLastMonthStatsByModel(User $user): array
    {
        $month = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        return $this->cache->get('ai_last_month_by_model_' . $user->getId() . '_' . $month, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(86400); // 24h — last month never changes
            $start = new \DateTimeImmutable('first day of last month 00:00:00');
            $end   = new \DateTimeImmutable('first day of this month 00:00:00');
            return $this->createQueryBuilder('r')
                ->select('r.modelVersion as model, SUM(r.tokensInput) as tokens_input, SUM(r.tokensOutput) as tokens_output, COUNT(r.id) as calls')
                ->leftJoin('r.video', 'v')
                ->where('v.user = :user OR r.video IS NULL')
                ->andWhere('r.generatedAt >= :start')
                ->andWhere('r.generatedAt < :end')
                ->andWhere('r.modelVersion IS NOT NULL')
                ->setParameter('user', $user)
                ->setParameter('start', $start)
                ->setParameter('end', $end)
                ->groupBy('r.modelVersion')
                ->getQuery()
                ->getArrayResult();
        });
    }

    /** Returns the number of distinct days this month where AI analyses were run. */
    public function countDistinctRunDaysThisMonth(User $user): int
    {
        $month = (new \DateTimeImmutable())->format('Y-m');
        return (int) $this->cache->get('ai_run_days_' . $user->getId() . '_' . $month, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(7200); // 2h
            $since = new \DateTimeImmutable('first day of this month 00:00:00');
            return (int) $this->createQueryBuilder('r')
                ->select('COUNT(DISTINCT SUBSTRING(r.generatedAt, 1, 10)) as run_days')
                ->leftJoin('r.video', 'v')
                ->where('v.user = :user OR r.video IS NULL')
                ->andWhere('r.generatedAt >= :since')
                ->andWhere('r.status = :done')
                ->setParameter('user', $user)
                ->setParameter('since', $since)
                ->setParameter('done', AiReportStatus::Done)
                ->getQuery()
                ->getSingleScalarResult();
        });
    }

    /** Returns token usage grouped by model version for the current month. */
    public function getMonthlyStatsByModel(User $user): array
    {
        $month = (new \DateTimeImmutable())->format('Y-m');
        return $this->cache->get('ai_monthly_by_model_' . $user->getId() . '_' . $month, function (ItemInterface $item) use ($user) {
            $item->expiresAfter(7200); // 2h
            $since = new \DateTimeImmutable('first day of this month 00:00:00');
            return $this->createQueryBuilder('r')
                ->select('r.modelVersion as model, SUM(r.tokensInput) as tokens_input, SUM(r.tokensOutput) as tokens_output, COUNT(r.id) as calls')
                ->leftJoin('r.video', 'v')
                ->where('v.user = :user OR r.video IS NULL')
                ->andWhere('r.generatedAt >= :since')
                ->andWhere('r.modelVersion IS NOT NULL')
                ->setParameter('user', $user)
                ->setParameter('since', $since)
                ->groupBy('r.modelVersion')
                ->getQuery()
                ->getArrayResult();
        });
    }
}
