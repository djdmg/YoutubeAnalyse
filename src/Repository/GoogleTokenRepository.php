<?php

namespace App\Repository;

use App\Entity\GoogleToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GoogleTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleToken::class);
    }

    public function findForUser(User $user): ?GoogleToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.channelId != :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', 'pending')
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findPendingForUser(User $user): ?GoogleToken
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasAnyRefreshToken(): bool
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.refreshToken IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /** @return GoogleToken[] */
    public function findAllWithRefreshToken(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.refreshToken IS NOT NULL')
            ->andWhere('t.channelId != :pending')
            ->setParameter('pending', 'pending')
            ->orderBy('t.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** @deprecated use findForUser */
    public function findLatest(): ?GoogleToken
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
