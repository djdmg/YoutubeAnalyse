<?php

namespace App\Repository;

use App\Entity\ChannelStats;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ChannelStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelStats::class);
    }

    public function findLatestForUser(User $user): ?ChannelStats
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findDailyStatsForUser(User $user, int $days = 90): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.recordedAt >= :from')
            ->setParameter('user', $user)
            ->setParameter('from', new \DateTimeImmutable("-{$days} days"))
            ->orderBy('s.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
