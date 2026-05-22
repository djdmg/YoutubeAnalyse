<?php
namespace App\Entity;

use App\Repository\DailyMetricRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DailyMetricRepository::class)]
#[ORM\Table(name: 'daily_metrics')]
#[ORM\Index(columns: ['video_id', 'date'], name: 'idx_daily_metric_video_date')]
#[ORM\UniqueConstraint(name: 'uniq_video_date', columns: ['video_id', 'date'])]
class DailyMetric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class, inversedBy: 'dailyMetrics')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Video $video = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'bigint')]
    private int $views = 0;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $impressions = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $ctr = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $watchTimeMinutes = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $avgRetentionPercent = null;

    #[ORM\Column(nullable: true)]
    private ?int $subscribersGained = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $trafficSources = null;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): ?Video { return $this->video; }
    public function setVideo(?Video $video): static { $this->video = $video; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getViews(): int { return $this->views; }
    public function setViews(int $views): static { $this->views = $views; return $this; }
    public function getImpressions(): ?int { return $this->impressions; }
    public function setImpressions(?int $impressions): static { $this->impressions = $impressions; return $this; }
    public function getCtr(): ?float { return $this->ctr; }
    public function setCtr(?float $ctr): static { $this->ctr = $ctr; return $this; }
    public function getWatchTimeMinutes(): ?int { return $this->watchTimeMinutes; }
    public function setWatchTimeMinutes(?int $watchTimeMinutes): static { $this->watchTimeMinutes = $watchTimeMinutes; return $this; }
    public function getAvgRetentionPercent(): ?float { return $this->avgRetentionPercent; }
    public function setAvgRetentionPercent(?float $avgRetentionPercent): static { $this->avgRetentionPercent = $avgRetentionPercent; return $this; }
    public function getSubscribersGained(): ?int { return $this->subscribersGained; }
    public function setSubscribersGained(?int $subscribersGained): static { $this->subscribersGained = $subscribersGained; return $this; }
    public function getTrafficSources(): ?array { return $this->trafficSources; }
    public function setTrafficSources(?array $trafficSources): static { $this->trafficSources = $trafficSources; return $this; }
}
