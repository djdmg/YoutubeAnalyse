<?php
namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    public function findNewForVideo(Video $video, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.video = :video')
            ->setParameter('video', $video)
            ->orderBy('c.publishedAt', 'DESC');

        if ($since) {
            $qb->andWhere('c.syncedAt > :since')->setParameter('since', $since);
        }

        return $qb->setMaxResults(50)->getQuery()->getResult();
    }

    public function findLatestSyncedAt(Video $video): ?\DateTimeImmutable
    {
        $result = $this->createQueryBuilder('c')
            ->select('MAX(c.syncedAt)')
            ->where('c.video = :video')
            ->setParameter('video', $video)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? new \DateTimeImmutable($result) : null;
    }

    public function countNewSinceLastAnalysis(Video $video, ?\DateTimeImmutable $since): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.video = :video')
            ->setParameter('video', $video);

        if ($since) {
            $qb->andWhere('c.syncedAt > :since')->setParameter('since', $since);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.publishedAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
