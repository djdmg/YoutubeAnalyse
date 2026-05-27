<?php

namespace App\Repository;

use App\Entity\Goal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Goal>
 */
class GoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Goal::class);
    }

    /** Returns all non-achieved goals for a user, ordered by creation date. */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->andWhere('g.isAchieved = false')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Returns all goals (active and achieved) for a user. */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
