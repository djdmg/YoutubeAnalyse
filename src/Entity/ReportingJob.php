<?php

namespace App\Entity;

use App\Repository\ReportingJobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReportingJobRepository::class)]
#[ORM\Table(name: 'reporting_job')]
#[ORM\UniqueConstraint(name: 'uniq_reporting_job_user_type', columns: ['user_id', 'report_type_id'])]
class ReportingJob
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 60)]
    private string $reportTypeId;

    #[ORM\Column(length: 100)]
    private string $googleJobId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastProcessedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getReportTypeId(): string { return $this->reportTypeId; }
    public function setReportTypeId(string $reportTypeId): static { $this->reportTypeId = $reportTypeId; return $this; }

    public function getGoogleJobId(): string { return $this->googleJobId; }
    public function setGoogleJobId(string $googleJobId): static { $this->googleJobId = $googleJobId; return $this; }

    public function getLastProcessedAt(): ?\DateTimeImmutable { return $this->lastProcessedAt; }
    public function setLastProcessedAt(?\DateTimeImmutable $dt): static { $this->lastProcessedAt = $dt; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
