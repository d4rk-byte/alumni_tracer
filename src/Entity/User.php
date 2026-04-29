<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_GOOGLE_SUBJECT', fields: ['googleSubject'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
#[UniqueEntity(fields: ['schoolId'], message: 'There is already an account with this school ID')]
#[Assert\Callback('validateRoleConsistency')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ALUMNI = 'ROLE_ALUMNI';
    public const ROLE_CODE_ADMIN = 1;
    public const ROLE_CODE_STAFF = 2;
    public const ROLE_CODE_USER = 3;

    private const ROLE_CODES_BY_NAME = [
        'ROLE_ADMIN' => self::ROLE_CODE_ADMIN,
        'ROLE_STAFF' => self::ROLE_CODE_STAFF,
        'ROLE_USER' => self::ROLE_CODE_USER,
        self::ROLE_ALUMNI => self::ROLE_CODE_USER,
    ];

    private const ROLE_NAMES_BY_CODE = [
        self::ROLE_CODE_ADMIN => ['ROLE_ADMIN'],
        self::ROLE_CODE_STAFF => ['ROLE_STAFF'],
        self::ROLE_CODE_USER => ['ROLE_USER', self::ROLE_ALUMNI],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleSubject = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $lastName = null;

    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $schoolId = null;

    /** @var list<int|string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 50)]
    // Default applies only on new object creation; explicit setAccountStatus() values are persisted as-is.
    private string $accountStatus = 'pending'; // pending, active, inactive

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $dateRegistered;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $profileCompletedAt = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresOnboarding = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastLogin = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastActivity = null;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: Alumni::class, cascade: ['persist'])]
    private ?Alumni $alumni = null;

    /** @var Collection<int, GtsSurvey> */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: GtsSurvey::class)]
    private Collection $gtsSurveys;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $dpaConsent = false;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dpaConsentDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $profileImage = null;

    public function __construct()
    {
        $this->dateRegistered = new \DateTime();
        $this->gtsSurveys = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static
    {
        $normalizedEmail = strtolower(trim($email));
        $this->email = $normalizedEmail;

        if ($this->alumni !== null && $this->alumni->getEmailAddress() !== $normalizedEmail) {
            $this->alumni->setEmailAddress($normalizedEmail);
        }

        return $this;
    }

    public function getGoogleSubject(): ?string { return $this->googleSubject; }
    public function setGoogleSubject(?string $googleSubject): static
    {
        $normalizedSubject = trim((string) $googleSubject);
        $this->googleSubject = $normalizedSubject !== '' ? $normalizedSubject : null;

        return $this;
    }

    public function getFirstName(): ?string { return $this->firstName; }
    public function setFirstName(string $firstName): static { $this->firstName = $firstName; return $this; }

    public function getLastName(): ?string { return $this->lastName; }
    public function setLastName(string $lastName): static { $this->lastName = $lastName; return $this; }

    public function getSchoolId(): ?string { return $this->schoolId; }
    public function setSchoolId(?string $schoolId): static { $this->schoolId = $schoolId; return $this; }

    public function getUserIdentifier(): string { return (string) $this->email; }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = [];

        foreach ($this->getRoleCodes() as $roleCode) {
            foreach (self::ROLE_NAMES_BY_CODE[$roleCode] ?? [] as $roleName) {
                $roles[] = $roleName;
            }
        }

        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @return list<int> */
    public function getRoleCodes(): array
    {
        return self::normalizeRoleCodes($this->roles);
    }

    public function getPrimaryRoleCode(): int
    {
        return $this->getRoleCodes()[0] ?? self::ROLE_CODE_USER;
    }

    public static function normalizeRoleCode(string|int $role): int
    {
        if (is_int($role)) {
            return array_key_exists($role, self::ROLE_NAMES_BY_CODE) ? $role : self::ROLE_CODE_USER;
        }

        $normalizedRole = strtoupper(trim($role));

        if ($normalizedRole !== '' && ctype_digit($normalizedRole)) {
            return self::normalizeRoleCode((int) $normalizedRole);
        }

        return self::ROLE_CODES_BY_NAME[$normalizedRole] ?? self::ROLE_CODE_USER;
    }

    /** @return list<string> */
    public static function getLegacyRoleNames(string|int $role): array
    {
        $roleCode = self::normalizeRoleCode($role);

        return self::ROLE_NAMES_BY_CODE[$roleCode] ?? ['ROLE_USER'];
    }

    /** @return list<string> */
    public static function getRoleStoragePatterns(string|int $role): array
    {
        $roleCode = self::normalizeRoleCode($role);
        $patterns = [];

        foreach (self::getLegacyRoleNames($roleCode) as $roleName) {
            $patterns[] = '%"' . $roleName . '"%';
        }

        $patterns[] = '[' . $roleCode . ']';
        $patterns[] = '[' . $roleCode . ',%';
        $patterns[] = '%,' . $roleCode . ',%';
        $patterns[] = '%,' . $roleCode . ']';

        return array_values(array_unique($patterns));
    }

    /** @param list<int|string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = self::normalizeRoleCodes($roles);

        return $this;
    }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    public function getAccountStatus(): string { return $this->accountStatus; }
    public function setAccountStatus(string $v): static { $this->accountStatus = $v; return $this; }

    public function getDateRegistered(): \DateTimeInterface { return $this->dateRegistered; }
    public function setDateRegistered(\DateTimeInterface $v): static { $this->dateRegistered = $v; return $this; }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): static
    {
        $this->emailVerifiedAt = $emailVerifiedAt;

        return $this;
    }

    public function getProfileCompletedAt(): ?\DateTimeImmutable { return $this->profileCompletedAt; }
    public function setProfileCompletedAt(?\DateTimeImmutable $profileCompletedAt): static
    {
        $this->profileCompletedAt = $profileCompletedAt;

        return $this;
    }

    public function isRequiresOnboarding(): bool { return $this->requiresOnboarding; }
    public function setRequiresOnboarding(bool $requiresOnboarding): static
    {
        $this->requiresOnboarding = $requiresOnboarding;

        return $this;
    }

    public function getLastLogin(): ?\DateTimeInterface { return $this->lastLogin; }
    public function setLastLogin(?\DateTimeInterface $v): static { $this->lastLogin = $v; return $this; }

    public function getLastActivity(): ?\DateTimeInterface { return $this->lastActivity; }
    public function setLastActivity(?\DateTimeInterface $v): static { $this->lastActivity = $v; return $this; }

    public function getAlumni(): ?Alumni { return $this->alumni; }
    public function setAlumni(?Alumni $alumni): static { $this->alumni = $alumni; return $this; }

    /** @return Collection<int, GtsSurvey> */
    public function getGtsSurveys(): Collection { return $this->gtsSurveys; }

    /** Keep a convenience accessor for the first/legacy submission (null if none). */
    public function getGtsSurvey(): ?GtsSurvey { return $this->gtsSurveys->first() ?: null; }

    public function getFullName(): string { return $this->firstName . ' ' . $this->lastName; }

    public function isAdmin(): bool { return in_array(self::ROLE_CODE_ADMIN, $this->getRoleCodes(), true); }

    public function validateRoleConsistency(ExecutionContextInterface $context): void
    {
        $roleCodes = $this->getRoleCodes();
        $isAlumniAccount = $this->alumni !== null || in_array(self::ROLE_CODE_USER, $roleCodes, true);
        $isAdminAccount = in_array(self::ROLE_CODE_ADMIN, $roleCodes, true);

        if ($isAlumniAccount && $isAdminAccount) {
            $context->buildViolation('Alumni accounts cannot be assigned ROLE_ADMIN.')
                ->atPath('roles')
                ->addViolation();
        }
    }

    /** @param list<int|string> $roles */
    private static function normalizeRoleCodes(array $roles): array
    {
        if ($roles === []) {
            return [self::ROLE_CODE_USER];
        }

        $normalizedCodes = [];

        foreach ($roles as $role) {
            $normalizedCodes[] = self::normalizeRoleCode($role);
        }

        $normalizedCodes = array_values(array_unique($normalizedCodes));
        sort($normalizedCodes);

        return $normalizedCodes !== [] ? $normalizedCodes : [self::ROLE_CODE_USER];
    }

    public function isDpaConsent(): bool { return $this->dpaConsent; }
    public function setDpaConsent(bool $v): static { $this->dpaConsent = $v; return $this; }

    public function getDpaConsentDate(): ?\DateTimeInterface { return $this->dpaConsentDate; }
    public function setDpaConsentDate(?\DateTimeInterface $v): static { $this->dpaConsentDate = $v; return $this; }

    public function getProfileImage(): ?string { return $this->profileImage; }
    public function setProfileImage(?string $v): static { $this->profileImage = $v; return $this; }
}
