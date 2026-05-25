<?php

namespace App\Repository;

use App\Entity\Video;
use App\Entity\VideoSearchTerm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VideoSearchTerm>
 */
class VideoSearchTermRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VideoSearchTerm::class);
    }

    /** @return VideoSearchTerm[] ordered by views desc */
    public function findTopForVideo(Video $video, int $limit = 25): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.video = :video')
            ->setParameter('video', $video)
            ->orderBy('t.views', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByQuery(Video $video, string $query): ?VideoSearchTerm
    {
        return $this->findOneBy(['video' => $video, 'query' => $query]);
    }
}
