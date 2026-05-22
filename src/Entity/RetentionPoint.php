<?php
namespace App\Entity;

use App\Repository\RetentionPointRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RetentionPointRepository::class)]
#[ORM\Table(name: 'retention_points')]
#[ORM\Index(columns: ['video_id', 'date'], name: 'idx_retention_video_date')]
class RetentionPoint
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class, inversedBy: 'retentionPoints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Video $video = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column]
    private int $second = 0;

    #[ORM\Column(type: 'float')]
    private float $retentionPercent = 0.0;

    public function __construct()
    {
        $this->date = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): ?Video { return $this->video; }
    public function setVideo(?Video $video): static { $this->video = $video; return $this; }
    public function getDate(): \DateTimeImmutable { return $this->date; }
    public function setDate(\DateTimeImmutable $date): static { $this->date = $date; return $this; }
    public function getSecond(): int { return $this->second; }
    public function setSecond(int $second): static { $this->second = $second; return $this; }
    public function getRetentionPercent(): float { return $this->retentionPercent; }
    public function setRetentionPercent(float $retentionPercent): static { $this->retentionPercent = $retentionPercent; return $this; }
}
