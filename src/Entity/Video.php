<?php
namespace App\Entity;

use App\Repository\VideoRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Table(name: 'videos')]
#[ORM\UniqueConstraint(name: 'uniq_youtube_id', columns: ['youtube_id'])]
class Video
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private string $youtubeId = '';

    #[ORM\Column(length: 500)]
    private string $title = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $genre = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationSeconds = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $thumbnailUrl = null;

    #[ORM\Column(length: 255)]
    private string $channelId = '';

    #[ORM\OneToMany(targetEntity: DailyMetric::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    private Collection $dailyMetrics;

    #[ORM\OneToMany(targetEntity: RetentionPoint::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    private Collection $retentionPoints;

    #[ORM\OneToMany(targetEntity: Comment::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    private Collection $comments;

    #[ORM\OneToMany(targetEntity: AiReport::class, mappedBy: 'video', cascade: ['remove'], orphanRemoval: true)]
    private Collection $aiReports;

    public function __construct()
    {
        $this->dailyMetrics   = new ArrayCollection();
        $this->retentionPoints = new ArrayCollection();
        $this->comments       = new ArrayCollection();
        $this->aiReports      = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getYoutubeId(): string { return $this->youtubeId; }
    public function setYoutubeId(string $youtubeId): static { $this->youtubeId = $youtubeId; return $this; }
    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(?\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }
    public function getGenre(): ?string { return $this->genre; }
    public function setGenre(?string $genre): static { $this->genre = $genre; return $this; }
    public function getDurationSeconds(): ?int { return $this->durationSeconds; }
    public function setDurationSeconds(?int $durationSeconds): static { $this->durationSeconds = $durationSeconds; return $this; }
    public function getThumbnailUrl(): ?string { return $this->thumbnailUrl; }
    public function setThumbnailUrl(?string $thumbnailUrl): static { $this->thumbnailUrl = $thumbnailUrl; return $this; }
    public function getChannelId(): string { return $this->channelId; }
    public function setChannelId(string $channelId): static { $this->channelId = $channelId; return $this; }
    public function getDailyMetrics(): Collection { return $this->dailyMetrics; }
    public function getRetentionPoints(): Collection { return $this->retentionPoints; }
    public function getComments(): Collection { return $this->comments; }
    public function getAiReports(): Collection { return $this->aiReports; }
}
