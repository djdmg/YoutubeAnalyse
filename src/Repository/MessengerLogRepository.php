<?php

namespace App\Repository;

use App\Entity\MessengerLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MessengerLog>
 */
class MessengerLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessengerLog::class);
    }

    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.status, COUNT(m.id) as cnt')
            ->groupBy('m.status')
            ->getQuery()
            ->getArrayResult();

        $result = ['pending' => 0, 'processing' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }
        return $result;
    }

    public function getAvgDurationMs(string $messageClass): ?float
    {
        return $this->createQueryBuilder('m')
            ->select('AVG(m.durationMs)')
            ->where('m.messageClass = :cls AND m.status = :s')
            ->setParameter('cls', $messageClass)
            ->setParameter('s', 'success')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteAll(): int
    {
        return (int) $this->createQueryBuilder('m')
            ->delete()
            ->getQuery()
            ->execute();
    }

    public function failStaleProcessing(\DateTimeImmutable $before, string $reason): int
    {
        return (int) $this->createQueryBuilder('m')
            ->update()
            ->set('m.status', ':failed')
            ->set('m.error', ':reason')
            ->set('m.finishedAt', ':now')
            ->where('m.status IN (:statuses)')
            ->andWhere('m.createdAt < :before')
            ->setParameter('failed', 'failed')
            ->setParameter('reason', $reason)
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('statuses', ['processing', 'retry'])
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function countStaleProcessing(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.status IN (:statuses)')
            ->andWhere('m.createdAt < :before')
            ->setParameter('statuses', ['processing', 'retry'])
            ->setParameter('before', $before)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
