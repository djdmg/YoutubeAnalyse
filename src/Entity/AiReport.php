<?php
namespace App\Entity;

use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiReportRepository::class)]
#[ORM\Table(name: 'ai_reports')]
#[ORM\Index(columns: ['video_id', 'type', 'generated_at'], name: 'idx_ai_report_video_type_date')]
class AiReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class, inversedBy: 'aiReports')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Video $video = null;

    #[ORM\Column(length: 50, enumType: AiReportType::class)]
    private AiReportType $type;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(length: 20, enumType: AiReportStatus::class)]
    private AiReportStatus $status = AiReportStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $generatedAt;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $modelVersion = null;

    #[ORM\Column(nullable: true)]
    private ?int $tokensInput = null;

    #[ORM\Column(nullable: true)]
    private ?int $tokensOutput = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    public function __construct()
    {
        $this->generatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): ?Video { return $this->video; }
    public function setVideo(?Video $video): static { $this->video = $video; return $this; }
    public function getType(): AiReportType { return $this->type; }
    public function setType(AiReportType $type): static { $this->type = $type; return $this; }
    public function getPayload(): ?array { return $this->payload; }
    public function setPayload(?array $payload): static { $this->payload = $payload; return $this; }
    public function getStatus(): AiReportStatus { return $this->status; }
    public function setStatus(AiReportStatus $status): static { $this->status = $status; return $this; }
    public function getGeneratedAt(): \DateTimeImmutable { return $this->generatedAt; }
    public function setGeneratedAt(\DateTimeImmutable $generatedAt): static { $this->generatedAt = $generatedAt; return $this; }
    public function getModelVersion(): ?string { return $this->modelVersion; }
    public function setModelVersion(?string $modelVersion): static { $this->modelVersion = $modelVersion; return $this; }
    public function getTokensInput(): ?int { return $this->tokensInput; }
    public function setTokensInput(?int $tokensInput): static { $this->tokensInput = $tokensInput; return $this; }
    public function getTokensOutput(): ?int { return $this->tokensOutput; }
    public function setTokensOutput(?int $tokensOutput): static { $this->tokensOutput = $tokensOutput; return $this; }
    public function getTokensUsed(): int { return ($this->tokensInput ?? 0) + ($this->tokensOutput ?? 0); }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function setDurationMs(?int $durationMs): static { $this->durationMs = $durationMs; return $this; }
}
