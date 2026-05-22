<?php

namespace App\Entity;

use App\Repository\DemographicSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DemographicSnapshotRepository::class)]
#[ORM\Table(name: 'demographic_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_demo_snapshot', columns: ['video_id', 'date', 'age_group', 'gender'])]
#[ORM\Index(columns: ['video_id', 'date'], name: 'idx_demo_video_date')]
class DemographicSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    /** e.g. AGE_25_34 */
    #[ORM\Column(length: 20)]
    private string $ageGroup;

    /** FEMALE, MALE, GENDER_OTHER */
    #[ORM\Column(length: 20)]
    private string $gender;

    #[ORM\Column(type: 'float')]
    private float $viewsPercentage;

    public function getId(): ?int { return $this->id; }

    public function getVideo(): Video { return $this->video; }
    public function setVideo(Video $video): static { $this->video = $video; return $this; }

    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }

    public function getAgeGroup(): string { return $this->ageGroup; }
    public function setAgeGroup(string $ageGroup): static { $this->ageGroup = $ageGroup; return $this; }

    public function getGender(): string { return $this->gender; }
    public function setGender(string $gender): static { $this->gender = $gender; return $this; }

    public function getViewsPercentage(): float { return $this->viewsPercentage; }
    public function setViewsPercentage(float $viewsPercentage): static { $this->viewsPercentage = $viewsPercentage; return $this; }
}
