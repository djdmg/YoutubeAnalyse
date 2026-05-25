<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'uniq_google_id', columns: ['google_id'])]
#[ORM\UniqueConstraint(name: 'uniq_email', columns: ['email'])]
class User implements UserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $email = '';

    #[ORM\Column(length: 255)]
    private string $displayName = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarUrl = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $googleId = '';

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column]
    private bool $isApproved = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastLoginAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $notifEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpHost = null;

    #[ORM\Column(nullable: true)]
    private ?int $smtpPort = 587;

    /** starttls, ssl, none */
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $smtpEncryption = 'starttls';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $smtpPassword = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $telegramChatId = null;

    #[ORM\OneToMany(targetEntity: GoogleToken::class, mappedBy: 'user', cascade: ['remove'], orphanRemoval: true)]
    private Collection $googleTokens;

    public function __construct()
    {
        $this->createdAt   = new \DateTimeImmutable();
        $this->lastLoginAt = new \DateTimeImmutable();
        $this->googleTokens = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $displayName): static { $this->displayName = $displayName; return $this; }
    public function getAvatarUrl(): ?string { return $this->avatarUrl; }
    public function setAvatarUrl(?string $avatarUrl): static { $this->avatarUrl = $avatarUrl; return $this; }
    public function getGoogleId(): string { return $this->googleId; }
    public function setGoogleId(string $googleId): static { $this->googleId = $googleId; return $this; }
    public function isApproved(): bool { return $this->isApproved; }
    public function setIsApproved(bool $isApproved): static { $this->isApproved = $isApproved; return $this; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static { $this->approvedAt = $approvedAt; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getLastLoginAt(): \DateTimeImmutable { return $this->lastLoginAt; }
    public function setLastLoginAt(\DateTimeImmutable $lastLoginAt): static { $this->lastLoginAt = $lastLoginAt; return $this; }
    public function getNotifEmail(): ?string { return $this->notifEmail; }
    public function setNotifEmail(?string $v): static { $this->notifEmail = $v; return $this; }
    public function getSmtpHost(): ?string { return $this->smtpHost; }
    public function setSmtpHost(?string $v): static { $this->smtpHost = $v; return $this; }
    public function getSmtpPort(): ?int { return $this->smtpPort; }
    public function setSmtpPort(?int $v): static { $this->smtpPort = $v; return $this; }
    public function getSmtpEncryption(): ?string { return $this->smtpEncryption; }
    public function setSmtpEncryption(?string $v): static { $this->smtpEncryption = $v; return $this; }
    public function getSmtpUser(): ?string { return $this->smtpUser; }
    public function setSmtpUser(?string $v): static { $this->smtpUser = $v; return $this; }
    public function getSmtpPassword(): ?string { return $this->smtpPassword; }
    public function setSmtpPassword(?string $v): static { $this->smtpPassword = $v; return $this; }
    public function hasSmtpConfigured(): bool { return $this->smtpHost && $this->smtpUser && $this->smtpPassword && $this->notifEmail; }
    public function getTelegramChatId(): ?string { return $this->telegramChatId; }
    public function setTelegramChatId(?string $v): static { $this->telegramChatId = $v; return $this; }
    public function hasTelegramConfigured(): bool { return $this->telegramChatId !== null && $this->telegramChatId !== ''; }

    public function getGoogleTokens(): Collection { return $this->googleTokens; }
    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true); }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }
    public function eraseCredentials(): void {}
    public function getUserIdentifier(): string { return $this->email; }
}
