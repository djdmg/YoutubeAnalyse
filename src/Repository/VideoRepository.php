<?php
namespace App\Repository;

use App\Entity\User;
use App\Entity\Video;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Video>
 */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Video::class);
    }

    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->orderBy('v.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByYoutubeId(string $youtubeId): ?Video
    {
        return $this->findOneBy(['youtubeId' => $youtubeId]);
    }

    public function findRecentForUser(User $user, int $days = 90): array
    {
        $since = new \DateTimeImmutable("-{$days} days");
        return $this->createQueryBuilder('v')
            ->where('v.user = :user')
            ->andWhere('v.publishedAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('v.publishedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Returns the 10 best-performing videos by total views for reference in AI prompts */
    public function findTopPerformingForUser(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('v')
            ->leftJoin('v.dailyMetrics', 'dm')
            ->where('v.user = :user')
            ->setParameter('user', $user)
            ->groupBy('v.id')
            ->orderBy('SUM(dm.views)', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
