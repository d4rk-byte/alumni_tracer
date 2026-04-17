<?php

namespace App\Entity;

use App\Repository\JobPostingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: JobPostingRepository::class)]
class JobPosting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(length: 255)]
    private string $companyName;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $requirements = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $salaryRange = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $employmentType = null; // Full-time, Part-time, Contract, Freelance

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $industry = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $relatedCourse = null; // Target course/program for alignment

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $applicationLink = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $deadline = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageFilename = null;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $postedBy = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $datePosted;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateUpdated = null;

    public function __construct()
    {
        $this->datePosted = new \DateTime();
    }

    // ── Getters & Setters ──

    public function getId(): ?int { return $this->id; }

    public function getTitle(): string { return $this->title; }
    public function setTitle(string $v): static { $this->title = $v; return $this; }

    public function getCompanyName(): string { return $this->companyName; }
    public function setCompanyName(string $v): static { $this->companyName = $v; return $this; }

    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $v): static { $this->location = $v; return $this; }

    public function getDescription(): string { return $this->description; }
    public function setDescription(string $v): static { $this->description = $v; return $this; }

    public function getRequirements(): ?string { return $this->requirements; }
    public function setRequirements(?string $v): static { $this->requirements = $v; return $this; }

    public function getSalaryRange(): ?string { return $this->salaryRange; }
    public function setSalaryRange(?string $v): static { $this->salaryRange = $v; return $this; }

    public function getEmploymentType(): ?string { return $this->employmentType; }
    public function setEmploymentType(?string $v): static { $this->employmentType = $v; return $this; }

    public function getIndustry(): ?string { return $this->industry; }
    public function setIndustry(?string $v): static { $this->industry = $v; return $this; }

    public function getRelatedCourse(): ?string { return $this->relatedCourse; }
    public function setRelatedCourse(?string $v): static { $this->relatedCourse = $v; return $this; }

    public function getContactEmail(): ?string { return $this->contactEmail; }
    public function setContactEmail(?string $v): static { $this->contactEmail = $v; return $this; }

    public function getApplicationLink(): ?string { return $this->applicationLink; }
    public function setApplicationLink(?string $v): static
    {
        if ($v !== null && $v !== '' && !preg_match('#^https?://#i', $v)) {
            throw new \InvalidArgumentException('Application link must use http or https scheme.');
        }
        $this->applicationLink = $v;
        return $this;
    }

    public function getDeadline(): ?\DateTimeInterface { return $this->deadline; }
    public function setDeadline(?\DateTimeInterface $v): static { $this->deadline = $v; return $this; }

    public function getImageFilename(): ?string { return $this->imageFilename; }
    public function setImageFilename(?string $v): static { $this->imageFilename = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): static { $this->isActive = $v; return $this; }

    public function getPostedBy(): ?User { return $this->postedBy; }
    public function setPostedBy(?User $v): static { $this->postedBy = $v; return $this; }

    public function getDatePosted(): \DateTimeInterface { return $this->datePosted; }
    public function setDatePosted(\DateTimeInterface $v): static { $this->datePosted = $v; return $this; }

    public function getDateUpdated(): ?\DateTimeInterface { return $this->dateUpdated; }
    public function setDateUpdated(?\DateTimeInterface $v): static { $this->dateUpdated = $v; return $this; }

    public function isExpired(): bool
    {
        if ($this->deadline === null) {
            return false;
        }
        return $this->deadline < new \DateTime('today');
    }
}
