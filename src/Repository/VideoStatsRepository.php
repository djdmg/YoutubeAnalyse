<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\VideoStats;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class VideoStatsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoStats::class);
    }

    public function findMostRecentForUser(User $user): array
    {
        // Get the max recordedAt
        $maxDate = $this->createQueryBuilder('sub')
            ->select('MAX(sub.recordedAt)')
            ->where('sub.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$maxDate) {
            return [];
        }

        // Fetch all videos within 60 seconds of the latest sync
        $maxDt = new \DateTimeImmutable($maxDate);
        $window = $maxDt->modify('-60 seconds');

        return $this->createQueryBuilder('v')
            ->where('v.user = :user')
            ->andWhere('v.recordedAt >= :window')
            ->setParameter('user', $user)
            ->setParameter('window', $window)
            ->orderBy('v.viewCount', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
