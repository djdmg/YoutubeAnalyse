<?php

namespace App\Entity;

use App\Repository\GoalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GoalRepository::class)]
#[ORM\Table(name: 'goals')]
class Goal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** subscribers | views | watch_time */
    #[ORM\Column(length: 50)]
    private string $type = 'views';

    #[ORM\Column(type: 'bigint')]
    private int $targetValue = 0;

    #[ORM\Column(type: 'bigint')]
    private int $currentValue = 0;

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deadline = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private bool $isAchieved = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $achievedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }
    public function getTargetValue(): int { return $this->targetValue; }
    public function setTargetValue(int $targetValue): static { $this->targetValue = $targetValue; return $this; }
    public function getCurrentValue(): int { return $this->currentValue; }
    public function setCurrentValue(int $currentValue): static { $this->currentValue = $currentValue; return $this; }
    public function getLabel(): string { return $this->label; }
    public function setLabel(string $label): static { $this->label = $label; return $this; }
    public function getDeadline(): ?\DateTimeImmutable { return $this->deadline; }
    public function setDeadline(?\DateTimeImmutable $deadline): static { $this->deadline = $deadline; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function isAchieved(): bool { return $this->isAchieved; }
    public function setIsAchieved(bool $isAchieved): static { $this->isAchieved = $isAchieved; return $this; }
    public function getAchievedAt(): ?\DateTimeImmutable { return $this->achievedAt; }
    public function setAchievedAt(?\DateTimeImmutable $achievedAt): static { $this->achievedAt = $achievedAt; return $this; }

    public function getProgressPercent(): float
    {
        if ($this->targetValue <= 0) return 0.0;
        return min(100.0, round($this->currentValue / $this->targetValue * 100, 1));
    }
}
