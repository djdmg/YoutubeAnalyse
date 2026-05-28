<?php

namespace App\Entity;

use App\Repository\ThumbnailChangeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThumbnailChangeRepository::class)]
#[ORM\Table(name: 'thumbnail_changes')]
class ThumbnailChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Video $video;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $oldUrl = null;

    #[ORM\Column(length: 500)]
    private string $newUrl = '';

    #[ORM\Column]
    private \DateTimeImmutable $appliedAt;

    public function __construct(Video $video, ?string $oldUrl, string $newUrl)
    {
        $this->video     = $video;
        $this->oldUrl    = $oldUrl;
        $this->newUrl    = $newUrl;
        $this->appliedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): Video { return $this->video; }
    public function getOldUrl(): ?string { return $this->oldUrl; }
    public function getNewUrl(): string { return $this->newUrl; }
    public function getAppliedAt(): \DateTimeImmutable { return $this->appliedAt; }
}
