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
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
#[UniqueEntity(fields: ['schoolId'], message: 'There is already an account with this school ID')]
#[Assert\Callback('validateRoleConsistency')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public const ROLE_ALUMNI = 'ROLE_ALUMNI';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $firstName = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $lastName = null;

    #[ORM\Column(length: 50, unique: true, nullable: true)]
    private ?string $schoolId = null;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 50)]
    // Default applies only on new object creation; explicit setAccountStatus() values are persisted as-is.
    private string $accountStatus = 'pending'; // pending, active, inactive

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $dateRegistered;

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
        $this->email = strtolower(trim($email));

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
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    public function getAccountStatus(): string { return $this->accountStatus; }
    public function setAccountStatus(string $v): static { $this->accountStatus = $v; return $this; }

    public function getDateRegistered(): \DateTimeInterface { return $this->dateRegistered; }
    public function setDateRegistered(\DateTimeInterface $v): static { $this->dateRegistered = $v; return $this; }

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

    public function isAdmin(): bool { return in_array('ROLE_ADMIN', $this->roles, true); }

    public function validateRoleConsistency(ExecutionContextInterface $context): void
    {
        $isAlumniAccount = $this->alumni !== null || in_array(self::ROLE_ALUMNI, $this->roles, true);
        $isAdminAccount = in_array('ROLE_ADMIN', $this->roles, true);

        if ($isAlumniAccount && $isAdminAccount) {
            $context->buildViolation('Alumni accounts cannot be assigned ROLE_ADMIN.')
                ->atPath('roles')
                ->addViolation();
        }
    }

    public function isDpaConsent(): bool { return $this->dpaConsent; }
    public function setDpaConsent(bool $v): static { $this->dpaConsent = $v; return $this; }

    public function getDpaConsentDate(): ?\DateTimeInterface { return $this->dpaConsentDate; }
    public function setDpaConsentDate(?\DateTimeInterface $v): static { $this->dpaConsentDate = $v; return $this; }

    public function getProfileImage(): ?string { return $this->profileImage; }
    public function setProfileImage(?string $v): static { $this->profileImage = $v; return $this; }
}
