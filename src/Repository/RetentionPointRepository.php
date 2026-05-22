<?php
namespace App\Repository;

use App\Entity\RetentionPoint;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RetentionPoint>
 */
class RetentionPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RetentionPoint::class);
    }

    public function findLatestForVideo(Video $video): array
    {
        $latestDate = $this->createQueryBuilder('rp')
            ->select('MAX(rp.date)')
            ->where('rp.video = :video')
            ->setParameter('video', $video)
            ->getQuery()
            ->getSingleScalarResult();

        if (!$latestDate) return [];

        return $this->createQueryBuilder('rp')
            ->where('rp.video = :video')
            ->andWhere('rp.date = :date')
            ->setParameter('video', $video)
            ->setParameter('date', new \DateTimeImmutable($latestDate))
            ->orderBy('rp.second', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
