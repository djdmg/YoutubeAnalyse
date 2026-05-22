<?php

namespace App\Entity;

use App\Repository\VideoStatsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoStatsRepository::class)]
#[ORM\Table(name: 'video_stats')]
#[ORM\Index(columns: ['video_id', 'recorded_at'], name: 'idx_video_date')]
class VideoStats
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $videoId = '';

    #[ORM\Column(length: 255)]
    private string $channelId = '';

    #[ORM\Column(length: 500)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(type: 'bigint')]
    private int $viewCount = 0;

    #[ORM\Column(type: 'bigint')]
    private int $likeCount = 0;

    #[ORM\Column(type: 'bigint')]
    private int $commentCount = 0;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $watchTimeMinutes = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    private ?string $averageViewPercentage = null;

    #[ORM\Column(nullable: true)]
    private ?int $subscribersGained = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $duration = null;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getVideoId(): string { return $this->videoId; }
    public function setVideoId(string $videoId): static { $this->videoId = $videoId; return $this; }
    public function getChannelId(): string { return $this->channelId; }
    public function setChannelId(string $channelId): static { $this->channelId = $channelId; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getThumbnailUrl(): ?string { return $this->thumbnailUrl; }
    public function setThumbnailUrl(?string $thumbnailUrl): static { $this->thumbnailUrl = $thumbnailUrl; return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }
    public function getViewCount(): int { return $this->viewCount; }
    public function setViewCount(int $viewCount): static { $this->viewCount = $viewCount; return $this; }
    public function getLikeCount(): int { return $this->likeCount; }
    public function setLikeCount(int $likeCount): static { $this->likeCount = $likeCount; return $this; }
    public function getCommentCount(): int { return $this->commentCount; }
    public function setCommentCount(int $commentCount): static { $this->commentCount = $commentCount; return $this; }
    public function getWatchTimeMinutes(): ?int { return $this->watchTimeMinutes; }
    public function setWatchTimeMinutes(?int $watchTimeMinutes): static { $this->watchTimeMinutes = $watchTimeMinutes; return $this; }
    public function getAverageViewPercentage(): ?string { return $this->averageViewPercentage; }
    public function setAverageViewPercentage(?string $v): static { $this->averageViewPercentage = $v; return $this; }
    public function getSubscribersGained(): ?int { return $this->subscribersGained; }
    public function setSubscribersGained(?int $subscribersGained): static { $this->subscribersGained = $subscribersGained; return $this; }
    public function getDuration(): ?string { return $this->duration; }
    public function setDuration(?string $duration): static { $this->duration = $duration; return $this; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
}
