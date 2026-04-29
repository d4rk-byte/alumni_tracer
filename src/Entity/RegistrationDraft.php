<?php

namespace App\Entity;

use App\Repository\RegistrationDraftRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegistrationDraftRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_REGISTRATION_DRAFT_EMAIL', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_REGISTRATION_DRAFT_STUDENT_ID', fields: ['studentId'])]
class RegistrationDraft
{
    public const FLOW_MANUAL = 'manual';
    public const FLOW_GOOGLE = 'google';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $flowType = self::FLOW_MANUAL;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 50)]
    private string $studentId;

    #[ORM\Column(length: 255)]
    private string $firstName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $middleName = null;

    #[ORM\Column(length: 255)]
    private string $lastName;

    #[ORM\Column(nullable: true)]
    private ?int $yearGraduated = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $college = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(length: 255)]
    private string $passwordHashTemp;

    #[ORM\Column(length: 255)]
    private string $otpCodeHash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $otpExpiresAt;

    #[ORM\Column(options: ['default' => 0])]
    private int $verifyAttempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $resendCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $dpaConsent = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->otpExpiresAt = new \DateTimeImmutable('+10 minutes');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFlowType(): string
    {
        return $this->flowType;
    }

    public function setFlowType(string $flowType): static
    {
        $normalizedFlowType = trim($flowType);
        $this->flowType = $normalizedFlowType !== '' ? $normalizedFlowType : self::FLOW_MANUAL;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getStudentId(): string
    {
        return $this->studentId;
    }

    public function setStudentId(string $studentId): static
    {
        $this->studentId = trim($studentId);

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);

        return $this;
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function setMiddleName(?string $middleName): static
    {
        $normalizedMiddleName = trim((string) $middleName);
        $this->middleName = $normalizedMiddleName !== '' ? $normalizedMiddleName : null;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);

        return $this;
    }

    public function getYearGraduated(): ?int
    {
        return $this->yearGraduated;
    }

    public function setYearGraduated(?int $yearGraduated): static
    {
        $this->yearGraduated = $yearGraduated;

        return $this;
    }

    public function getCollege(): ?string
    {
        return $this->college;
    }

    public function setCollege(?string $college): static
    {
        $normalizedCollege = trim((string) $college);
        $this->college = $normalizedCollege !== '' ? $normalizedCollege : null;

        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $normalizedDepartment = trim((string) $department);
        $this->department = $normalizedDepartment !== '' ? $normalizedDepartment : null;

        return $this;
    }

    public function getPasswordHashTemp(): string
    {
        return $this->passwordHashTemp;
    }

    public function setPasswordHashTemp(string $passwordHashTemp): static
    {
        $this->passwordHashTemp = $passwordHashTemp;

        return $this;
    }

    public function getOtpCodeHash(): string
    {
        return $this->otpCodeHash;
    }

    public function setOtpCodeHash(string $otpCodeHash): static
    {
        $this->otpCodeHash = $otpCodeHash;

        return $this;
    }

    public function getOtpExpiresAt(): \DateTimeImmutable
    {
        return $this->otpExpiresAt;
    }

    public function setOtpExpiresAt(\DateTimeImmutable $otpExpiresAt): static
    {
        $this->otpExpiresAt = $otpExpiresAt;

        return $this;
    }

    public function getVerifyAttempts(): int
    {
        return $this->verifyAttempts;
    }

    public function setVerifyAttempts(int $verifyAttempts): static
    {
        $this->verifyAttempts = max(0, $verifyAttempts);

        return $this;
    }

    public function incrementVerifyAttempts(): static
    {
        ++$this->verifyAttempts;

        return $this;
    }

    public function getResendCount(): int
    {
        return $this->resendCount;
    }

    public function setResendCount(int $resendCount): static
    {
        $this->resendCount = max(0, $resendCount);

        return $this;
    }

    public function incrementResendCount(): static
    {
        ++$this->resendCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    public function isDpaConsent(): bool
    {
        return $this->dpaConsent;
    }

    public function setDpaConsent(bool $dpaConsent): static
    {
        $this->dpaConsent = $dpaConsent;

        return $this;
    }
}