<?php

namespace App\Entity;

use App\Repository\MessengerLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessengerLogRepository::class)]
#[ORM\Table(name: 'messenger_log')]
#[ORM\Index(columns: ['message_class'], name: 'idx_msg_class')]
#[ORM\Index(columns: ['status'], name: 'idx_msg_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_msg_date')]
class MessengerLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $messageClass;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(length: 20)]
    private string $status = 'processing'; // processing | success | failed | retry

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $retryCount = null;

    public function __construct(string $messageClass, array $payload)
    {
        $this->messageClass = $messageClass;
        $this->payload      = $payload;
        $this->createdAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMessageClass(): string { return $this->messageClass; }
    public function getPayload(): array { return $this->payload; }
    public function getStatus(): string { return $this->status; }
    public function getError(): ?string { return $this->error; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getDurationMs(): ?int { return $this->durationMs; }
    public function getRetryCount(): ?int { return $this->retryCount; }

    public function markSuccess(int $durationMs): void
    {
        $this->status     = 'success';
        $this->finishedAt = new \DateTimeImmutable();
        $this->durationMs = $durationMs;
    }

    public function markFailed(string $error, int $durationMs, int $retryCount = 0): void
    {
        $this->status     = 'failed';
        $this->error      = $error;
        $this->finishedAt = new \DateTimeImmutable();
        $this->durationMs = $durationMs;
        $this->retryCount = $retryCount;
    }

    public function markRetry(string $error, int $retryCount): void
    {
        $this->status     = 'retry';
        $this->error      = $error;
        $this->retryCount = $retryCount;
    }
}
