<?php

namespace App\Controller;

use App\Entity\RegistrationDraft;
use App\Repository\QrRegistrationBatchRepository;
use App\Service\NotificationService;
use App\Service\RegistrationDraftService;
use App\Service\RegistrationOtpService;
use App\Service\RegistrationValidationException;
use App\Service\SystemSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/register')]
final class RegistrationApiController extends AbstractController
{
    #[Route('/options', name: 'api_register_options', methods: ['GET'])]
    public function options(QrRegistrationBatchRepository $batchRepository, SystemSettingsService $systemSettings): JsonResponse
    {
        return $this->json([
            'publicSignupEnabled' => $systemSettings->isPublicSignupEnabled(),
            'batchYears' => array_map(
                static fn ($batch): int => $batch->getBatchYear(),
                $batchRepository->findOpenOrdered(),
            ),
        ]);
    }

    #[Route('', name: 'api_register_create', methods: ['POST'])]
    public function create(
        Request $request,
        RegistrationDraftService $draftService,
        NotificationService $notifier,
        SystemSettingsService $systemSettings,
    ): JsonResponse {
        if (!$systemSettings->isPublicSignupEnabled()) {
            return $this->json([
                'message' => 'Public sign-up is currently disabled. Please register through an official QR registration link.',
                'errors' => ['form' => 'Public sign-up is currently disabled.'],
            ], 403);
        }

        $payload = $this->jsonPayload($request);
        $password = (string) ($payload['password'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? '');

        $fieldErrors = $this->validatePassword($password, $confirmPassword);

        if (($payload['dataPrivacyConsent'] ?? false) !== true) {
            $fieldErrors['dataPrivacyConsent'] = 'You must agree to the Data Privacy Act compliance statement.';
        }

        if ($fieldErrors !== []) {
            return $this->json(['message' => 'Registration data is invalid.', 'errors' => $fieldErrors], 422);
        }

        try {
            $draftResult = $draftService->createManualDraft([
                'email' => (string) ($payload['email'] ?? ''),
                'studentId' => (string) ($payload['studentId'] ?? ''),
                'firstName' => (string) ($payload['firstName'] ?? ''),
                'lastName' => (string) ($payload['lastName'] ?? ''),
                'plainPassword' => $password,
                'yearGraduated' => is_numeric($payload['yearGraduated'] ?? null) ? (int) $payload['yearGraduated'] : null,
                'dpaConsent' => true,
            ]);

            $otpSent = true;

            try {
                $notifier->sendRegistrationOtp($draftResult['draft'], $draftResult['otpCode']);
            } catch (\Throwable) {
                $otpSent = false;
            }

            /** @var RegistrationDraft $draft */
            $draft = $draftResult['draft'];

            return $this->json([
                'draftId' => $draft->getId(),
                'email' => $draft->getEmail(),
                'otpSent' => $otpSent,
                'message' => $otpSent
                    ? 'We sent a verification code to your email.'
                    : 'Registration draft was saved, but the verification code could not be sent yet.',
            ], 201);
        } catch (RegistrationValidationException $exception) {
            return $this->json([
                'message' => 'Registration data is invalid.',
                'errors' => $exception->getFieldErrors(),
            ], 422);
        }
    }

    #[Route('/qr/{batchYear<\d{4}>}', name: 'api_register_qr_create', methods: ['POST'])]
    public function createFromQr(
        int $batchYear,
        Request $request,
        RegistrationDraftService $draftService,
        NotificationService $notifier,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $payload['yearGraduated'] = $batchYear;
        $password = (string) ($payload['password'] ?? '');
        $confirmPassword = (string) ($payload['confirmPassword'] ?? '');

        $fieldErrors = $this->validatePassword($password, $confirmPassword);

        if (($payload['dataPrivacyConsent'] ?? false) !== true) {
            $fieldErrors['dataPrivacyConsent'] = 'You must agree to the Data Privacy Act compliance statement.';
        }

        if ($fieldErrors !== []) {
            return $this->json(['message' => 'Registration data is invalid.', 'errors' => $fieldErrors], 422);
        }

        try {
            $draftResult = $draftService->createManualDraft([
                'email' => (string) ($payload['email'] ?? ''),
                'studentId' => (string) ($payload['studentId'] ?? ''),
                'firstName' => (string) ($payload['firstName'] ?? ''),
                'lastName' => (string) ($payload['lastName'] ?? ''),
                'plainPassword' => $password,
                'yearGraduated' => $batchYear,
                'flowType' => RegistrationDraft::FLOW_QR,
                'dpaConsent' => true,
            ]);

            $otpSent = true;

            try {
                $notifier->sendRegistrationOtp($draftResult['draft'], $draftResult['otpCode']);
            } catch (\Throwable) {
                $otpSent = false;
            }

            /** @var RegistrationDraft $draft */
            $draft = $draftResult['draft'];

            return $this->json([
                'draftId' => $draft->getId(),
                'email' => $draft->getEmail(),
                'otpSent' => $otpSent,
                'message' => $otpSent
                    ? 'We sent a verification code to your email.'
                    : 'Registration draft was saved, but the verification code could not be sent yet.',
            ], 201);
        } catch (RegistrationValidationException $exception) {
            return $this->json([
                'message' => 'Registration data is invalid.',
                'errors' => $exception->getFieldErrors(),
            ], 422);
        }
    }

    #[Route('/verify-email', name: 'api_register_verify_email', methods: ['POST'])]
    public function verifyEmail(
        Request $request,
        RegistrationDraftService $draftService,
        RegistrationOtpService $otpService,
        NotificationService $notifier,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $draft = $draftService->findPendingDraftById(is_numeric($payload['draftId'] ?? null) ? (int) $payload['draftId'] : null);

        if ($draft === null) {
            return $this->json(['message' => 'Registration draft was not found. Please start registration again.'], 404);
        }

        $submittedCode = (string) ($payload['otpCode'] ?? '');

        if ($otpService->isExpired($draft)) {
            return $this->json(['message' => 'This verification code has expired. Request a new code and try again.'], 422);
        }

        if (!$otpService->hasVerifyAttemptsRemaining($draft)) {
            return $this->json(['message' => 'You have used all verification attempts for this code. Request a new code to continue.'], 422);
        }

        if (!$otpService->isCodeValid($draft, $submittedCode)) {
            $draft->incrementVerifyAttempts();
            $draftService->save($draft);

            return $this->json([
                'message' => sprintf('The verification code is incorrect. %d attempt(s) remaining.', $otpService->getRemainingAttempts($draft)),
            ], 422);
        }

        try {
            $registeredUser = $draftService->finalizeManualDraft($draft);

            try {
                $notifier->notifyNewRegistration($registeredUser);
            } catch (\Throwable) {
            }

            return $this->json([
                'message' => $registeredUser->getAccountStatus() === 'active'
                    ? 'Email verified successfully. Your alumni account is now active.'
                    : 'Email verified successfully. Your registration is now awaiting approval.',
                'accountStatus' => $registeredUser->getAccountStatus(),
            ]);
        } catch (RegistrationValidationException $exception) {
            return $this->json([
                'message' => 'Registration data is invalid.',
                'errors' => $exception->getFieldErrors(),
            ], 422);
        }
    }

    #[Route('/resend-otp', name: 'api_register_resend_otp', methods: ['POST'])]
    public function resendOtp(
        Request $request,
        RegistrationDraftService $draftService,
        RegistrationOtpService $otpService,
        NotificationService $notifier,
    ): JsonResponse {
        $payload = $this->jsonPayload($request);
        $draft = $draftService->findPendingDraftById(is_numeric($payload['draftId'] ?? null) ? (int) $payload['draftId'] : null);

        if ($draft === null) {
            return $this->json(['message' => 'Registration draft was not found. Please start registration again.'], 404);
        }

        if (!$otpService->hasResendsRemaining($draft)) {
            return $this->json(['message' => 'You have reached the maximum number of resend attempts for this registration.'], 422);
        }

        $otpCode = $draftService->reissueOtp($draft);

        try {
            $notifier->sendRegistrationOtp($draft, $otpCode);
        } catch (\Throwable) {
            return $this->json(['message' => 'We could not send a new verification code right now. Please try again shortly.'], 503);
        }

        return $this->json([
            'message' => 'A new verification code has been sent to your email address.',
            'remainingResends' => $otpService->getRemainingResends($draft),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, string>
     */
    private function validatePassword(string $password, string $confirmPassword): array
    {
        $errors = [];

        if ($password === '') {
            $errors['password'] = 'Please enter a password.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Your password should be at least 8 characters.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).+$/', $password)) {
            $errors['password'] = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
        }

        if ($password !== $confirmPassword) {
            $errors['confirmPassword'] = 'The password fields must match.';
        }

        return $errors;
    }
}
