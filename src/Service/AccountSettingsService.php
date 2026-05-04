<?php

namespace App\Service;

use App\Entity\Alumni;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AccountSettingsService
{
    private const PROFILE_UPLOAD_DIR = '/public/uploads/profile';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {}

    /**
     * @return array{item: array<string, mixed>}
     */
    public function getSettings(User $user, string $baseUrl): array
    {
        return ['item' => $this->serializeUser($user, $baseUrl)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{item?: array<string, mixed>, errors?: array<string, string>}
     */
    public function updateSettings(User $user, array $payload, string $baseUrl): array
    {
        $fullName = $this->normalizeString($payload['fullName'] ?? '');
        $email = strtolower($this->normalizeString($payload['email'] ?? ''));
        $username = array_key_exists('username', $payload)
            ? $this->normalizeNullableString($payload['username'])
            : $user->getUsername();
        $phoneNumber = array_key_exists('phoneNumber', $payload)
            ? $this->normalizeNullableString($payload['phoneNumber'])
            : $user->getPhoneNumber();
        $bio = array_key_exists('bio', $payload)
            ? $this->normalizeNullableString($payload['bio'])
            : $user->getBio();
        $errors = [];

        if ($fullName === '') {
            $errors['fullName'] = 'Please enter your full name.';
        }

        [$firstName, $lastName] = $this->splitFullName($fullName);

        if ($firstName === '' || $lastName === '') {
            $errors['fullName'] = 'Please enter both first and last name.';
        }

        if ($email === '') {
            $errors['email'] = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } else {
            $existingEmail = $this->userRepository->findOneBy(['email' => $email]);
            if ($existingEmail !== null && $existingEmail->getId() !== $user->getId()) {
                $errors['email'] = 'This email address is already associated with an account.';
            }
        }

        if ($username !== null) {
            if (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
                $errors['username'] = 'Username must be 3-80 characters and use letters, numbers, dot, dash, or underscore only.';
            } else {
                $existingUsername = $this->userRepository->findOneBy(['username' => $username]);
                if ($existingUsername !== null && $existingUsername->getId() !== $user->getId()) {
                    $errors['username'] = 'This username is already taken.';
                }
            }
        }

        if ($phoneNumber !== null && strlen($phoneNumber) > 50) {
            $errors['phoneNumber'] = 'Phone number must be 50 characters or fewer.';
        }

        if ($bio !== null && strlen($bio) > 1000) {
            $errors['bio'] = 'Bio must be 1000 characters or fewer.';
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $user
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setEmail($email)
            ->setUsername($username)
            ->setPhoneNumber($phoneNumber)
            ->setBio($bio);

        $this->entityManager->flush();

        return ['item' => $this->serializeUser($user, $baseUrl)];
    }

    /**
     * @return array{item?: array<string, mixed>, errors?: array<string, string>}
     */
    public function updatePhoto(User $user, ?UploadedFile $photo, string $baseUrl): array
    {
        if (!$photo instanceof UploadedFile) {
            return ['errors' => ['photo' => 'Please choose a profile photo.']];
        }

        if (!$photo->isValid()) {
            return ['errors' => ['photo' => 'The photo upload failed. Please try again.']];
        }

        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $mimeType = (string) $photo->getMimeType();

        if (!in_array($mimeType, $allowedMimeTypes, true)) {
            return ['errors' => ['photo' => 'Invalid image format. Please upload JPG, PNG, or WEBP.']];
        }

        if ($photo->getSize() !== null && $photo->getSize() > 10 * 1024 * 1024) {
            return ['errors' => ['photo' => 'Image is too large. Maximum file size is 10MB.']];
        }

        $uploadDir = $this->getUploadDir();

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return ['errors' => ['photo' => 'Unable to prepare the profile upload directory.']];
        }

        $extension = $photo->guessExtension() ?: match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $newFilename = sprintf('user_%d_%s.%s', $user->getId(), bin2hex(random_bytes(8)), $extension);

        try {
            $photo->move($uploadDir, $newFilename);
        } catch (\Throwable) {
            return ['errors' => ['photo' => 'Image upload failed. Please try again.']];
        }

        $this->deleteProfileImage($user);
        $user->setProfileImage($newFilename);
        $this->entityManager->flush();

        return ['item' => $this->serializeUser($user, $baseUrl)];
    }

    /**
     * @return array{item: array<string, mixed>}
     */
    public function removePhoto(User $user, string $baseUrl): array
    {
        $this->deleteProfileImage($user);
        $user->setProfileImage(null);
        $this->entityManager->flush();

        return ['item' => $this->serializeUser($user, $baseUrl)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{item?: array<string, mixed>, errors?: array<string, string>}
     */
    public function changePassword(User $user, array $payload, string $baseUrl): array
    {
        $currentPassword = (string) ($payload['currentPassword'] ?? '');
        $newPassword = (string) ($payload['newPassword'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? '');
        $errors = [];

        if ($currentPassword === '' || !$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            $errors['currentPassword'] = 'Current password is incorrect.';
        }

        if ($newPassword === '') {
            $errors['newPassword'] = 'Please enter a new password.';
        } elseif (strlen($newPassword) < 8) {
            $errors['newPassword'] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/', $newPassword)) {
            $errors['newPassword'] = 'Password must contain uppercase, lowercase, number, and special character.';
        } elseif ($this->passwordHasher->isPasswordValid($user, $newPassword)) {
            $errors['newPassword'] = 'New password must be different from your current password.';
        }

        if ($newPassword !== $confirmPassword) {
            $errors['confirmPassword'] = 'The password fields must match.';
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        return ['item' => $this->serializeUser($user, $baseUrl)];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user, string $baseUrl): array
    {
        $profileImage = $user->getProfileImage();
        $roles = $user->getRoles();
        $alumni = $user->getAlumni();

        return [
            'id' => $user->getId(),
            'fullName' => $user->getFullName(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername() ?? '',
            'phoneNumber' => $user->getPhoneNumber() ?? $user->getAlumni()?->getContactNumber() ?? '',
            'bio' => $user->getBio() ?? '',
            'schoolId' => $user->getSchoolId(),
            'roles' => $roles,
            'primaryRole' => match ($user->getPrimaryRoleCode()) {
                User::ROLE_CODE_ADMIN => 'admin',
                User::ROLE_CODE_STAFF => 'staff',
                default => 'alumni',
            },
            'hasAlumniRecord' => $alumni instanceof Alumni,
            'alumni' => $alumni instanceof Alumni ? $this->serializeAlumni($alumni) : null,
            'profileImage' => $profileImage,
            'profileImageUrl' => $profileImage ? rtrim($baseUrl, '/') . '/uploads/profile/' . $profileImage : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAlumni(Alumni $alumni): array
    {
        return [
            'id' => $alumni->getId(),
            'studentNumber' => $alumni->getStudentNumber(),
            'fullName' => $alumni->getFullName(),
            'firstName' => $alumni->getFirstName(),
            'lastName' => $alumni->getLastName(),
            'emailAddress' => $alumni->getEmailAddress(),
            'contactNumber' => $alumni->getContactNumber(),
            'homeAddress' => $alumni->getHomeAddress(),
            'province' => $alumni->getProvince(),
            'college' => $alumni->getCollege(),
            'course' => $alumni->getCourse(),
            'degreeProgram' => $alumni->getDegreeProgram(),
            'yearGraduated' => $alumni->getYearGraduated(),
            'employmentStatus' => $alumni->getEmploymentStatus(),
            'companyName' => $alumni->getCompanyName(),
            'jobTitle' => $alumni->getJobTitle(),
            'tracerStatus' => $alumni->getTracerStatus(),
            'lastTracerSubmissionAt' => $alumni->getLastTracerSubmissionAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitFullName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $firstName = array_shift($parts) ?? '';
        $lastName = trim(implode(' ', $parts));

        return [$firstName, $lastName];
    }

    private function normalizeString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function getUploadDir(): string
    {
        return $this->projectDir . self::PROFILE_UPLOAD_DIR;
    }

    private function deleteProfileImage(User $user): void
    {
        $profileImage = $user->getProfileImage();

        if (!$profileImage) {
            return;
        }

        $imagePath = $this->getUploadDir() . '/' . $profileImage;

        if (is_file($imagePath)) {
            @unlink($imagePath);
        }
    }
}
