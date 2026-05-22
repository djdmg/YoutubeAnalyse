<?php

namespace App\Repository;

use App\Entity\ReportingJob;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReportingJob>
 */
class ReportingJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReportingJob::class);
    }

    public function findForUserAndType(User $user, string $reportTypeId): ?ReportingJob
    {
        return $this->findOneBy(['user' => $user, 'reportTypeId' => $reportTypeId]);
    }

    /** @return ReportingJob[] */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }
}
