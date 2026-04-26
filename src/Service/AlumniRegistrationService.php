<?php

namespace App\Service;

use App\Entity\Alumni;
use App\Entity\User;
use App\Repository\AlumniRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AlumniRegistrationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $userRepository,
        private AlumniRepository $alumniRepository,
        private UserPasswordHasherInterface $userPasswordHasher,
    ) {
    }

    /**
     * @param array{
     *     email: string,
     *     studentId: string,
     *     firstName: string,
     *     middleName?: ?string,
     *     lastName: string,
     *     plainPassword: string,
     *     yearGraduated: ?int,
     *     college?: ?string,
     *     course?: ?string,
     *     degreeProgram?: ?string,
     *     dpaConsent?: bool
     * } $registration
     */
    public function register(array $registration, string $accountStatus): User
    {
        $email = strtolower(trim($registration['email']));
        $studentId = trim($registration['studentId']);
        $firstName = trim($registration['firstName']);
        $middleName = $this->normalizeOptionalString($registration['middleName'] ?? null);
        $lastName = trim($registration['lastName']);
        $plainPassword = $registration['plainPassword'];
        $yearGraduated = $registration['yearGraduated'];
        $college = $this->normalizeOptionalString($registration['college'] ?? null);
        $course = $this->normalizeOptionalString($registration['course'] ?? null);
        $degreeProgram = $this->normalizeOptionalString($registration['degreeProgram'] ?? null);
        $dpaConsent = $registration['dpaConsent'] ?? true;

        $fieldErrors = [];

        if ($studentId === '') {
            $fieldErrors['studentId'] = 'Please enter your student ID.';
        }

        if ($firstName === '') {
            $fieldErrors['firstName'] = 'Please enter your first name.';
        }

        if ($lastName === '') {
            $fieldErrors['lastName'] = 'Please enter your last name.';
        }

        if ($email === '') {
            $fieldErrors['email'] = 'Please enter your email address.';
        }

        if ($yearGraduated === null) {
            $fieldErrors['yearGraduated'] = 'Please enter your batch year.';
        }

        if ($email !== '' && $this->userRepository->findOneBy(['email' => $email]) !== null) {
            $fieldErrors['email'] = 'This email address is already associated with an account.';
        }

        if ($studentId !== '' && $this->userRepository->findOneBy(['schoolId' => $studentId]) !== null) {
            $fieldErrors['studentId'] = 'This student ID is already registered.';
        }

        $alumniByEmail = $email !== '' ? $this->alumniRepository->findOneBy(['emailAddress' => $email]) : null;
        $alumniByStudentId = $studentId !== '' ? $this->alumniRepository->findOneBy(['studentNumber' => $studentId]) : null;

        if (
            $alumniByEmail instanceof Alumni
            && $alumniByStudentId instanceof Alumni
            && $alumniByEmail->getId() !== $alumniByStudentId->getId()
        ) {
            throw new RegistrationValidationException([
                'email' => 'This email and student ID matched different alumni records. Please contact the alumni office for manual cleanup.',
                'studentId' => 'This email and student ID matched different alumni records. Please contact the alumni office for manual cleanup.',
            ]);
        }

        if ($fieldErrors !== []) {
            throw new RegistrationValidationException($fieldErrors);
        }

        $alumni = $alumniByEmail ?? $alumniByStudentId;

        if ($alumni instanceof Alumni && $alumni->getUser() !== null) {
            $fieldErrors = [];

            if ($alumniByEmail instanceof Alumni) {
                $fieldErrors['email'] = 'This email address is already associated with an account.';
            }

            if ($alumniByStudentId instanceof Alumni) {
                $fieldErrors['studentId'] = 'This student ID is already registered.';
            }

            throw new RegistrationValidationException($fieldErrors !== []
                ? $fieldErrors
                : ['form' => 'This registration could not be completed because an account already exists for this alumni record.']);
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setSchoolId($studentId)
            ->setRoles([User::ROLE_ALUMNI])
            ->setAccountStatus($accountStatus)
            ->setDpaConsent($dpaConsent)
            ->setDpaConsentDate($dpaConsent ? new \DateTime() : null);

        $user->setPassword($this->userPasswordHasher->hashPassword($user, $plainPassword));

        if (!$alumni instanceof Alumni) {
            $alumni = new Alumni();
        }

        $alumni
            ->setStudentNumber($studentId)
            ->setFirstName($firstName)
            ->setMiddleName($middleName)
            ->setLastName($lastName)
            ->setEmailAddress($email)
            ->setYearGraduated($yearGraduated)
            ->setUser($user);

        if ($college !== null) {
            $alumni->setCollege($college);
        }

        if ($course !== null) {
            $alumni->setCourse($course);
        }

        if ($degreeProgram !== null) {
            $alumni->setDegreeProgram($degreeProgram);
        }

        $user->setAlumni($alumni);

        try {
            $this->entityManager->persist($user);
            $this->entityManager->persist($alumni);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new RegistrationValidationException([
                'form' => 'This registration could not be completed because the email or student ID is already in use.',
            ]);
        }

        return $user;
    }

    private function normalizeOptionalString(?string $value): ?string
    {
        $normalizedValue = trim((string) $value);

        return $normalizedValue !== '' ? $normalizedValue : null;
    }
}