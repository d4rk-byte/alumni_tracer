<?php

namespace App\Entity;

use App\Repository\QrRegistrationBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QrRegistrationBatchRepository::class)]
#[ORM\Table(name: 'qr_registration_batch')]
#[ORM\UniqueConstraint(name: 'uniq_qr_registration_batch_year', columns: ['batch_year'])]
class QrRegistrationBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'batch_year', type: Types::SMALLINT)]
    private int $batchYear;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(name: 'is_open', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isOpen = true;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int { return $this->id; }

    public function getBatchYear(): int { return $this->batchYear; }
    public function setBatchYear(int $batchYear): static
    {
        $this->batchYear = $batchYear;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function isOpen(): bool { return $this->isOpen; }
    public function setIsOpen(bool $isOpen): static
    {
        $this->isOpen = $isOpen;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->batchYear;
    }
}