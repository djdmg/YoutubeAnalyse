<?php
namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comments')]
#[ORM\UniqueConstraint(name: 'uniq_youtube_comment_id', columns: ['youtube_comment_id'])]
#[ORM\Index(columns: ['video_id'], name: 'idx_comment_video')]
class Comment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Video::class, inversedBy: 'comments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Video $video = null;

    #[ORM\Column(length: 100)]
    private string $youtubeCommentId = '';

    #[ORM\Column(type: 'text')]
    private string $text = '';

    #[ORM\Column]
    private \DateTimeImmutable $publishedAt;

    #[ORM\Column]
    private \DateTimeImmutable $syncedAt;

    public function __construct()
    {
        $this->publishedAt = new \DateTimeImmutable();
        $this->syncedAt    = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getVideo(): ?Video { return $this->video; }
    public function setVideo(?Video $video): static { $this->video = $video; return $this; }
    public function getYoutubeCommentId(): string { return $this->youtubeCommentId; }
    public function setYoutubeCommentId(string $youtubeCommentId): static { $this->youtubeCommentId = $youtubeCommentId; return $this; }
    public function getText(): string { return $this->text; }
    public function setText(string $text): static { $this->text = $text; return $this; }
    public function getPublishedAt(): \DateTimeImmutable { return $this->publishedAt; }
    public function setPublishedAt(\DateTimeImmutable $publishedAt): static { $this->publishedAt = $publishedAt; return $this; }
    public function getSyncedAt(): \DateTimeImmutable { return $this->syncedAt; }
    public function setSyncedAt(\DateTimeImmutable $syncedAt): static { $this->syncedAt = $syncedAt; return $this; }
}
