<?php

namespace App\Entity;

use App\Repository\ChannelStatsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChannelStatsRepository::class)]
#[ORM\Table(name: 'channel_stats')]
#[ORM\Index(columns: ['recorded_at'], name: 'idx_recorded_at')]
class ChannelStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $channelId = '';

    #[ORM\Column(length: 255)]
    private string $channelTitle = '';

    #[ORM\Column(type: 'bigint')]
    private int $viewCount = 0;

    #[ORM\Column(type: 'bigint')]
    private int $subscriberCount = 0;

    #[ORM\Column(type: 'bigint')]
    private int $videoCount = 0;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $watchTimeMinutes = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $averageViewDuration = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $likes = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $comments = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $shares = null;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getChannelId(): string { return $this->channelId; }
    public function setChannelId(string $channelId): static { $this->channelId = $channelId; return $this; }
    public function getChannelTitle(): string { return $this->channelTitle; }
    public function setChannelTitle(string $channelTitle): static { $this->channelTitle = $channelTitle; return $this; }
    public function getViewCount(): int { return $this->viewCount; }
    public function setViewCount(int $viewCount): static { $this->viewCount = $viewCount; return $this; }
    public function getSubscriberCount(): int { return $this->subscriberCount; }
    public function setSubscriberCount(int $subscriberCount): static { $this->subscriberCount = $subscriberCount; return $this; }
    public function getVideoCount(): int { return $this->videoCount; }
    public function setVideoCount(int $videoCount): static { $this->videoCount = $videoCount; return $this; }
    public function getWatchTimeMinutes(): ?int { return $this->watchTimeMinutes; }
    public function setWatchTimeMinutes(?int $watchTimeMinutes): static { $this->watchTimeMinutes = $watchTimeMinutes; return $this; }
    public function getAverageViewDuration(): ?string { return $this->averageViewDuration; }
    public function setAverageViewDuration(?string $v): static { $this->averageViewDuration = $v; return $this; }
    public function getLikes(): ?int { return $this->likes; }
    public function setLikes(?int $likes): static { $this->likes = $likes; return $this; }
    public function getComments(): ?int { return $this->comments; }
    public function setComments(?int $comments): static { $this->comments = $comments; return $this; }
    public function getShares(): ?int { return $this->shares; }
    public function setShares(?int $shares): static { $this->shares = $shares; return $this; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
}
