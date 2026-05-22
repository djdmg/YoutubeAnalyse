<?php

namespace App\Entity;

use App\Repository\GoogleTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: GoogleTokenRepository::class)]
#[ORM\Table(name: 'google_tokens')]
class GoogleToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'googleTokens')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private string $channelId = '';

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $channelTitle = null;

    #[ORM\Column(type: 'text')]
    private string $accessToken = '';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'text')]
    private string $rawToken = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }
    public function getChannelId(): string { return $this->channelId; }
    public function setChannelId(string $channelId): static { $this->channelId = $channelId; return $this; }
    public function getChannelTitle(): ?string { return $this->channelTitle; }
    public function setChannelTitle(?string $channelTitle): static { $this->channelTitle = $channelTitle; return $this; }
    public function getAccessToken(): string { return $this->accessToken; }
    public function setAccessToken(string $accessToken): static { $this->accessToken = $accessToken; return $this; }
    public function getRefreshToken(): ?string { return $this->refreshToken; }
    public function setRefreshToken(?string $refreshToken): static { $this->refreshToken = $refreshToken; return $this; }
    public function getExpiresAt(): \DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }
    public function getRawToken(): string { return $this->rawToken; }
    public function setRawToken(string $rawToken): static { $this->rawToken = $rawToken; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function isExpired(): bool { return $this->expiresAt <= new \DateTimeImmutable(); }
}
