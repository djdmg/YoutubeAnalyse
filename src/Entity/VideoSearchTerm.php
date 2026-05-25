<?php

namespace App\Entity;

use App\Repository\VideoSearchTermRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoSearchTermRepository::class)]
#[ORM\Table(name: 'video_search_terms')]
#[ORM\UniqueConstraint(name: 'uniq_video_query', columns: ['video_id', 'query'])]
class VideoSearchTerm
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(length: 500)]
    private string $query;

    #[ORM\Column]
    private int $views = 0;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function getId(): ?int { return $this->id; }

    public function getVideo(): Video { return $this->video; }
    public function setVideo(Video $video): static { $this->video = $video; return $this; }

    public function getQuery(): string { return $this->query; }
    public function setQuery(string $query): static { $this->query = $query; return $this; }

    public function getViews(): int { return $this->views; }
    public function setViews(int $views): static { $this->views = $views; return $this; }

    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }
}
