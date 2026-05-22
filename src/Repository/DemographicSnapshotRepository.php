<?php

namespace App\Repository;

use App\Entity\DemographicSnapshot;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DemographicSnapshot>
 */
class DemographicSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DemographicSnapshot::class);
    }

    /** Returns the most recent demographics for a video, aggregated across all dates. */
    public function findLatestForVideo(Video $video): array
    {
        $latestDate = $this->createQueryBuilder('d')
            ->select('MAX(d.date)')
            ->where('d.video = :video')
            ->setParameter('video', $video)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$latestDate) return [];

        return $this->findBy(['video' => $video, 'date' => new \DateTimeImmutable($latestDate)]);
    }

    /** Returns age/gender breakdown averaged over the last N days for a video. */
    public function findAggregatedForVideo(Video $video, int $days = 30): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('d')
            ->select('d.ageGroup, d.gender, AVG(d.viewsPercentage) as avgPct')
            ->where('d.video = :video')
            ->andWhere('d.date >= :since')
            ->setParameter('video', $video)
            ->setParameter('since', $since)
            ->groupBy('d.ageGroup, d.gender')
            ->orderBy('d.ageGroup')
            ->addOrderBy('d.gender')
            ->getQuery()
            ->getArrayResult();
    }
}
