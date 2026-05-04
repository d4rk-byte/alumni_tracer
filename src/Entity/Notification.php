<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_recipient_created', columns: ['recipient_id', 'created_at'])]
#[ORM\Index(name: 'idx_notification_recipient_read', columns: ['recipient_id', 'read_at'])]
#[ORM\Index(name: 'idx_notification_expires_at', columns: ['expires_at'])]
class Notification
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_SUCCESS = 'success';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_DANGER = 'danger';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 80)]
    private string $type = '';

    #[ORM\Column(length: 160)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(length: 20)]
    private string $severity = self::SEVERITY_INFO;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetUrl = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+30 days');
    }

    public function getId(): ?int { return $this->id; }

    public function getRecipient(): User { return $this->recipient; }
    public function setRecipient(User $recipient): static { $this->recipient = $recipient; return $this; }

    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $actor): static { $this->actor = $actor; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $title): static { $this->title = $title; return $this; }

    public function getMessage(): string { return $this->message; }
    public function setMessage(string $message): static { $this->message = $message; return $this; }

    public function getSeverity(): string { return $this->severity; }
    public function setSeverity(string $severity): static { $this->severity = $severity; return $this; }

    public function getTargetUrl(): ?string { return $this->targetUrl; }
    public function setTargetUrl(?string $targetUrl): static { $this->targetUrl = $targetUrl; return $this; }

    public function getEntityType(): ?string { return $this->entityType; }
    public function setEntityType(?string $entityType): static { $this->entityType = $entityType; return $this; }

    public function getEntityId(): ?int { return $this->entityId; }
    public function setEntityId(?int $entityId): static { $this->entityId = $entityId; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): static { $this->createdAt = $createdAt; return $this; }

    public function getReadAt(): ?\DateTimeImmutable { return $this->readAt; }
    public function setReadAt(?\DateTimeImmutable $readAt): static { $this->readAt = $readAt; return $this; }

    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static { $this->expiresAt = $expiresAt; return $this; }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }
}
