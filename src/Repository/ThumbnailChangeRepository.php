<?php

namespace App\Repository;

use App\Entity\ThumbnailChange;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ThumbnailChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThumbnailChange::class);
    }

    /** @return ThumbnailChange[] */
    public function findForVideo(Video $video): array
    {
        return $this->findBy(['video' => $video], ['appliedAt' => 'DESC']);
    }
}
