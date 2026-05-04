<?php

namespace App\Service;

use App\Entity\RegistrationDraft;
use App\Entity\User;
use App\Repository\QrRegistrationBatchRepository;
use App\Repository\RegistrationDraftRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationDraftService
{
    public const SESSION_KEY = 'registration_draft_id';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RegistrationDraftRepository $draftRepository,
        private QrRegistrationBatchRepository $batchRepository,
        private AlumniRegistrationService $registrationService,
        private RegistrationOtpService $otpService,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    /**
     * @param array{
     *     email: string,
     *     studentId: string,
     *     firstName: string,
     *     lastName: string,
     *     plainPassword: string,
     *     yearGraduated: ?int,
     *     flowType?: string,
     *     dpaConsent?: bool
     * } $registration
     *
     * @return array{draft: RegistrationDraft, otpCode: string}
     */
    public function createManualDraft(array $registration): array
    {
        $email = strtolower(trim($registration['email']));
        $studentId = trim($registration['studentId']);

        $this->assertBatchRegistrationIsOpen($registration['yearGraduated']);
        $this->registrationService->assertCanRegister($registration);

        $draft = $this->resolveEditableDraft($email, $studentId) ?? new RegistrationDraft();
        $draft
            ->setFlowType((string) ($registration['flowType'] ?? RegistrationDraft::FLOW_MANUAL))
            ->setEmail($email)
            ->setStudentId($studentId)
            ->setFirstName($registration['firstName'])
            ->setLastName($registration['lastName'])
            ->setYearGraduated($registration['yearGraduated'])
            ->setDpaConsent((bool) ($registration['dpaConsent'] ?? true))
            ->setPasswordHashTemp($this->hashPasswordForDraft($email, $registration['plainPassword']))
            ->setResendCount(0)
            ->setVerifiedAt(null);

        $otpCode = $this->otpService->issueOtp($draft);

        $this->entityManager->persist($draft);
        $this->entityManager->flush();

        return [
            'draft' => $draft,
            'otpCode' => $otpCode,
        ];
    }

    public function findPendingDraftById(?int $draftId): ?RegistrationDraft
    {
        if ($draftId === null || $draftId <= 0) {
            return null;
        }

        $draft = $this->draftRepository->find($draftId);

        if (!$draft instanceof RegistrationDraft || $draft->getVerifiedAt() !== null) {
            return null;
        }

        return $draft;
    }

    public function save(RegistrationDraft $draft): void
    {
        $this->entityManager->persist($draft);
        $this->entityManager->flush();
    }

    public function reissueOtp(RegistrationDraft $draft): string
    {
        $otpCode = $this->otpService->issueOtp($draft, true);
        $this->save($draft);

        return $otpCode;
    }

    public function finalizeManualDraft(RegistrationDraft $draft): User
    {
        $this->assertBatchRegistrationIsOpen($draft->getYearGraduated());

        $accountStatus = $draft->getFlowType() === RegistrationDraft::FLOW_QR ? 'active' : 'pending';

        $user = $this->registrationService->register([
            'email' => $draft->getEmail(),
            'studentId' => $draft->getStudentId(),
            'firstName' => $draft->getFirstName(),
            'middleName' => $draft->getMiddleName(),
            'lastName' => $draft->getLastName(),
            'passwordHash' => $draft->getPasswordHashTemp(),
            'yearGraduated' => $draft->getYearGraduated(),
            'college' => $draft->getCollege(),
            'course' => $draft->getDepartment(),
            'dpaConsent' => $draft->isDpaConsent(),
            'emailVerifiedAt' => new \DateTimeImmutable(),
        ], $accountStatus);

        $this->entityManager->remove($draft);
        $this->entityManager->flush();

        return $user;
    }

    private function hashPasswordForDraft(string $email, string $plainPassword): string
    {
        $user = (new User())->setEmail($email);

        return $this->passwordHasher->hashPassword($user, $plainPassword);
    }

    private function assertBatchRegistrationIsOpen(?int $batchYear): void
    {
        if ($batchYear === null || $this->batchRepository->findOneOpenByBatchYear($batchYear) === null) {
            throw new RegistrationValidationException([
                'yearGraduated' => 'This batch registration is currently closed. Please select an open batch year.',
            ]);
        }
    }

    private function resolveEditableDraft(string $email, string $studentId): ?RegistrationDraft
    {
        $draftByEmail = $this->draftRepository->findOneBy(['email' => $email]);
        $draftByStudentId = $this->draftRepository->findOneBy(['studentId' => $studentId]);

        if (
            $draftByEmail instanceof RegistrationDraft
            && $draftByStudentId instanceof RegistrationDraft
            && $draftByEmail->getId() !== $draftByStudentId->getId()
        ) {
            throw new RegistrationValidationException([
                'form' => 'A pending email verification already exists for this email or student ID. Complete that verification first or use different details.',
            ]);
        }

        if ($draftByEmail instanceof RegistrationDraft) {
            if ($draftByEmail->getStudentId() !== $studentId) {
                throw new RegistrationValidationException([
                    'studentId' => 'A pending verification already exists for this email address using a different student ID.',
                ]);
            }

            return $draftByEmail;
        }

        if ($draftByStudentId instanceof RegistrationDraft) {
            if ($draftByStudentId->getEmail() !== $email) {
                throw new RegistrationValidationException([
                    'email' => 'A pending verification already exists for this student ID using a different email address.',
                ]);
            }

            return $draftByStudentId;
        }

        return null;
    }
}
