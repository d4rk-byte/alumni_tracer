<?php

namespace App\Service;

use App\Entity\Alumni;
use App\Entity\Department;
use App\Entity\User;
use App\Repository\AlumniRepository;
use App\Repository\DepartmentRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class GoogleOnboardingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private AlumniRepository $alumniRepository,
        private DepartmentRepository $departmentRepository,
    ) {
    }

    public function needsOnboarding(User $user): bool
    {
        if ($user->isRequiresOnboarding()) {
            return true;
        }

        if (trim((string) $user->getGoogleSubject()) === '') {
            return false;
        }

        return $user->getProfileCompletedAt() === null
            || trim((string) $user->getSchoolId()) === ''
            || trim((string) $user->getFirstName()) === ''
            || trim((string) $user->getLastName()) === ''
            || $user->getAlumni() === null;
    }

    /**
     * @return array{
     *     schoolId: string,
     *     firstName: string,
     *     middleName: string,
     *     lastName: string,
     *     yearGraduated: ?int,
     *     college: string,
     *     department: string
     * }
     */
    public function buildInitialData(User $user): array
    {
        $alumni = $user->getAlumni();
        $departmentName = '';
        $collegeName = '';

        if ($alumni instanceof Alumni) {
            $departmentName = trim((string) ($alumni->getDegreeProgram() ?? ''));
            $collegeName = trim((string) ($alumni->getCollege() ?? ''));

            if ($departmentName === '' && trim((string) ($alumni->getCourse() ?? '')) !== '') {
                $resolvedDepartment = $this->departmentRepository->findOneBy(['code' => $alumni->getCourse()]);
                if ($resolvedDepartment instanceof Department) {
                    $departmentName = $resolvedDepartment->getName();
                    $collegeName = $collegeName !== ''
                        ? $collegeName
                        : (string) ($resolvedDepartment->getCollege()?->getName() ?? '');
                } else {
                    $departmentName = (string) $alumni->getCourse();
                }
            }
        }

        return [
            'schoolId' => (string) ($user->getSchoolId() ?? $alumni?->getStudentNumber() ?? ''),
            'firstName' => (string) ($user->getFirstName() ?? $alumni?->getFirstName() ?? ''),
            'middleName' => (string) ($alumni?->getMiddleName() ?? ''),
            'lastName' => (string) ($user->getLastName() ?? $alumni?->getLastName() ?? ''),
            'yearGraduated' => $alumni?->getYearGraduated(),
            'college' => $collegeName,
            'department' => $departmentName,
        ];
    }

    /**
     * @param array{
     *     schoolId?: string|null,
     *     firstName?: string|null,
     *     middleName?: string|null,
     *     lastName?: string|null,
     *     yearGraduated?: int|string|null,
     *     college?: string|null,
     *     department?: string|null
     * } $data
     */
    public function completeOnboarding(User $user, array $data): User
    {
        $schoolId = trim((string) ($data['schoolId'] ?? ''));
        $firstName = trim((string) ($data['firstName'] ?? ''));
        $middleName = trim((string) ($data['middleName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? ''));
        $collegeName = trim((string) ($data['college'] ?? ''));
        $departmentName = trim((string) ($data['department'] ?? ''));
        $yearGraduated = $data['yearGraduated'] ?? null;

        $fieldErrors = [];

        if ($schoolId === '') {
            $fieldErrors['schoolId'] = 'Please enter your school ID.';
        }

        if ($firstName === '') {
            $fieldErrors['firstName'] = 'Please enter your first name.';
        }

        if ($lastName === '') {
            $fieldErrors['lastName'] = 'Please enter your last name.';
        }

        if ($departmentName === '') {
            $fieldErrors['department'] = 'Please select your department.';
        }

        if ($collegeName === '' && $departmentName !== '') {
            $resolvedDepartment = $this->departmentRepository->findOneBy(['name' => $departmentName]);
            $collegeName = $resolvedDepartment instanceof Department ? (string) ($resolvedDepartment->getCollege()?->getName() ?? '') : '';
        }

        if ($collegeName === '') {
            $fieldErrors['college'] = 'Please select your college.';
        }

        if ($yearGraduated === null || trim((string) $yearGraduated) === '') {
            $fieldErrors['yearGraduated'] = 'Please enter your batch year.';
        }

        $resolvedDepartment = $departmentName !== '' ? $this->departmentRepository->findOneBy(['name' => $departmentName]) : null;
        if (!$resolvedDepartment instanceof Department) {
            if ($departmentName !== '') {
                $fieldErrors['department'] = 'Please select a valid department.';
            }
        } else {
            $resolvedCollege = $resolvedDepartment->getCollege();
            $resolvedCollegeName = (string) ($resolvedCollege?->getName() ?? '');

            if ($resolvedCollegeName === '') {
                $fieldErrors['college'] = 'The selected department is not linked to a college.';
            } elseif ($collegeName !== '' && $collegeName !== $resolvedCollegeName) {
                $fieldErrors['college'] = 'The selected college does not match the chosen department.';
            } else {
                $collegeName = $resolvedCollegeName;
            }
        }

        if ($fieldErrors !== []) {
            throw new RegistrationValidationException($fieldErrors);
        }

        $alumniByEmail = $this->alumniRepository->findOneBy(['emailAddress' => $user->getEmail()]);
        $alumniByStudentId = $this->alumniRepository->findOneBy(['studentNumber' => $schoolId]);

        if (
            $alumniByEmail instanceof Alumni
            && $alumniByStudentId instanceof Alumni
            && $alumniByEmail->getId() !== $alumniByStudentId->getId()
        ) {
            throw new RegistrationValidationException([
                'schoolId' => 'This school ID and email matched different alumni records. Please contact the alumni office for cleanup.',
            ]);
        }

        $alumni = $user->getAlumni() ?? $alumniByEmail ?? $alumniByStudentId ?? new Alumni();

        if ($alumni->getUser() !== null && $alumni->getUser()?->getId() !== $user->getId()) {
            throw new RegistrationValidationException([
                'schoolId' => 'This school ID is already linked to another account.',
            ]);
        }

        $departmentCode = $resolvedDepartment?->getCode() ?? $departmentName;

        $user
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setSchoolId($schoolId)
            ->setAccountStatus('active')
            ->setRequiresOnboarding(false)
            ->setProfileCompletedAt(new \DateTimeImmutable())
            ->setEmailVerifiedAt($user->getEmailVerifiedAt() ?? new \DateTimeImmutable())
            ->setDpaConsent(true)
            ->setDpaConsentDate($user->getDpaConsentDate() ?? new \DateTime());

        $alumni
            ->setStudentNumber($schoolId)
            ->setFirstName($firstName)
            ->setMiddleName($middleName !== '' ? $middleName : null)
            ->setLastName($lastName)
            ->setEmailAddress($user->getEmail() ?? '')
            ->setYearGraduated((int) $yearGraduated)
            ->setCollege($collegeName)
            ->setCourse($departmentCode)
            ->setDegreeProgram($departmentName)
            ->setUser($user);

        $user->setAlumni($alumni);

        $this->entityManager->persist($user);
        $this->entityManager->persist($alumni);
        $this->entityManager->flush();

        return $user;
    }
}