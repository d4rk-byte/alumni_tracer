<?php

namespace App\Entity;

use App\Repository\SurveyCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SurveyCampaignRepository::class)]
#[ORM\Table(name: 'survey_campaign')]
class SurveyCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'campaigns')]
    #[ORM\JoinColumn(nullable: false)]
    private GtsSurveyTemplate $surveyTemplate;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $emailSubject = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $emailBody = '';

    /** Graduation years targeted (e.g. ['2022', '2023']) */
    #[ORM\Column(type: Types::JSON)]
    private array $targetGraduationYears = [];

    /** Optional college filter (null = all colleges) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetCollege = null;

    /** Optional course filter (null = all courses) */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $targetCourse = null;

    /** Days from send time until the invitation link expires */
    #[ORM\Column]
    private int $expiryDays = 30;

    #[ORM\Column(length: 30)]
    private string $status = 'draft'; // draft | sending | sent | cancelled

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scheduledSendAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $createdBy = null;

    /** @var Collection<int, SurveyInvitation> */
    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: SurveyInvitation::class, cascade: ['remove'])]
    private Collection $invitations;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->invitations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSurveyTemplate(): GtsSurveyTemplate
    {
        return $this->surveyTemplate;
    }

    public function setSurveyTemplate(GtsSurveyTemplate $surveyTemplate): static
    {
        $this->surveyTemplate = $surveyTemplate;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmailSubject(): string
    {
        return $this->emailSubject;
    }

    public function setEmailSubject(string $emailSubject): static
    {
        $this->emailSubject = $emailSubject;

        return $this;
    }

    public function getEmailBody(): string
    {
        return $this->emailBody;
    }

    public function setEmailBody(string $emailBody): static
    {
        $this->emailBody = $emailBody;

        return $this;
    }

    public function getTargetGraduationYears(): array
    {
        return $this->targetGraduationYears;
    }

    public function getTargetBatchYear(): ?int
    {
        $rawValue = $this->targetGraduationYears[0] ?? null;
        if ($rawValue === null) {
            return null;
        }

        $normalizedValue = trim((string) $rawValue);
        if ($normalizedValue === '' || !is_numeric($normalizedValue)) {
            return null;
        }

        return (int) $normalizedValue;
    }

    public function setTargetBatchYear(?int $targetBatchYear): static
    {
        $this->targetGraduationYears = $targetBatchYear !== null ? [(string) $targetBatchYear] : [];

        return $this;
    }

    public function setTargetGraduationYears(array $targetGraduationYears): static
    {
        $this->targetGraduationYears = $targetGraduationYears;

        return $this;
    }

    public function getTargetCollege(): ?string
    {
        return $this->targetCollege;
    }

    public function setTargetCollege(?string $targetCollege): static
    {
        $this->targetCollege = $targetCollege;

        return $this;
    }

    public function getTargetCourse(): ?string
    {
        return $this->targetCourse;
    }

    public function setTargetCourse(?string $targetCourse): static
    {
        $this->targetCourse = $targetCourse;

        return $this;
    }

    public function getExpiryDays(): int
    {
        return $this->expiryDays;
    }

    public function setExpiryDays(int $expiryDays): static
    {
        $this->expiryDays = $expiryDays;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getScheduledSendAt(): ?\DateTimeImmutable
    {
        return $this->scheduledSendAt;
    }

    public function setScheduledSendAt(?\DateTimeImmutable $scheduledSendAt): static
    {
        $this->scheduledSendAt = $scheduledSendAt;

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** @return Collection<int, SurveyInvitation> */
    public function getInvitations(): Collection
    {
        return $this->invitations;
    }
}
