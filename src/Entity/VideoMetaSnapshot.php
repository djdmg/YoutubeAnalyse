<?php

namespace App\Entity;

use App\Repository\VideoMetaSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoMetaSnapshotRepository::class)]
#[ORM\Table(name: 'video_meta_snapshots')]
#[ORM\Index(columns: ['video_id', 'recorded_at'], name: 'idx_snapshot_video_date')]
class VideoMetaSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function getId(): ?int { return $this->id; }

    public function getVideo(): Video { return $this->video; }
    public function setVideo(Video $video): static { $this->video = $video; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }

    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    public function setRecordedAt(\DateTimeImmutable $recordedAt): static { $this->recordedAt = $recordedAt; return $this; }
}
