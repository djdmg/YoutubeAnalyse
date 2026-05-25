<?php

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoMetaSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoMetaSnapshot>
 */
class VideoMetaSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoMetaSnapshot::class);
    }

    public function findLatestForVideo(Video $video): ?VideoMetaSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.video = :video')
            ->setParameter('video', $video)
            ->orderBy('s.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return VideoMetaSnapshot[] ordered oldest first */
    public function findAllForVideo(Video $video): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.video = :video')
            ->setParameter('video', $video)
            ->orderBy('s.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
