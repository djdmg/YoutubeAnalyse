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

        $result = ['processing' => 0, 'pending' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];
        foreach ($rows as $row) {
            $result[$row['status']] = (int) $row['cnt'];
        }
        // Merge legacy 'pending' into 'processing' so old records appear in the right KPI card
        $result['processing'] += $result['pending'];
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
}
