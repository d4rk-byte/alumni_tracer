<?php

namespace App\Controller;

use App\Form\RegistrationOtpVerificationType;
use App\Service\NotificationService;
use App\Service\RegistrationDraftService;
use App\Service\RegistrationOtpService;
use App\Service\RegistrationValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationOtpController extends AbstractController
{
    #[Route('/register/verify-email', name: 'app_register_verify_email', methods: ['GET', 'POST'])]
    public function verifyEmail(
        Request $request,
        RegistrationDraftService $draftService,
        RegistrationOtpService $otpService,
        NotificationService $notifier,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $draft = $draftService->findPendingDraftById($request->getSession()->get(RegistrationDraftService::SESSION_KEY));

        if ($draft === null) {
            $request->getSession()->remove(RegistrationDraftService::SESSION_KEY);
            $this->addFlash('danger', 'Your registration draft was not found. Please start registration again.');

            return $this->redirectToRoute('app_register');
        }

        $form = $this->createForm(RegistrationOtpVerificationType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submittedCode = (string) $form->get('otpCode')->getData();

            if ($otpService->isExpired($draft)) {
                $form->get('otpCode')->addError(new FormError('This verification code has expired. Request a new code and try again.'));
            } elseif (!$otpService->hasVerifyAttemptsRemaining($draft)) {
                $form->addError(new FormError('You have used all verification attempts for this code. Request a new code to continue.'));
            } elseif (!$otpService->isCodeValid($draft, $submittedCode)) {
                $draft->incrementVerifyAttempts();
                $draftService->save($draft);

                $remainingAttempts = $otpService->getRemainingAttempts($draft);
                $form->addError(new FormError($remainingAttempts > 0
                    ? sprintf('The verification code is incorrect. %d attempt(s) remaining.', $remainingAttempts)
                    : 'The verification code is incorrect. Request a new code to continue.'));
            } else {
                try {
                    $registeredUser = $draftService->finalizeManualDraft($draft);
                    $request->getSession()->remove(RegistrationDraftService::SESSION_KEY);

                    try {
                        $notifier->notifyNewRegistration($registeredUser);
                    } catch (\Throwable) {
                        // Administrative notification failure should not block completed registration.
                    }

                    $this->addFlash('success', 'Email verified successfully. Your registration is now awaiting approval.');

                    return $this->redirectToRoute('app_login');
                } catch (RegistrationValidationException $exception) {
                    foreach ($exception->getFieldErrors() as $message) {
                        $form->addError(new FormError($message));
                    }
                }
            }
        }

        return $this->render('registration/verify_email_otp.html.twig', [
            'otpForm' => $form,
            'draftEmail' => $draft->getEmail(),
            'otpExpiryMinutes' => RegistrationOtpService::OTP_LIFETIME_MINUTES,
            'remainingResends' => $otpService->getRemainingResends($draft),
        ], new Response(status: $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }

    #[Route('/register/resend-otp', name: 'app_register_resend_otp', methods: ['POST'])]
    public function resendOtp(
        Request $request,
        RegistrationDraftService $draftService,
        RegistrationOtpService $otpService,
        NotificationService $notifier,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        if (!$this->isCsrfTokenValid('register_resend_otp', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('danger', 'Invalid resend request. Please try again.');

            return $this->redirectToRoute('app_register_verify_email');
        }

        $draft = $draftService->findPendingDraftById($request->getSession()->get(RegistrationDraftService::SESSION_KEY));

        if ($draft === null) {
            $request->getSession()->remove(RegistrationDraftService::SESSION_KEY);
            $this->addFlash('danger', 'Your registration draft was not found. Please start registration again.');

            return $this->redirectToRoute('app_register');
        }

        if (!$otpService->hasResendsRemaining($draft)) {
            $this->addFlash('danger', 'You have reached the maximum number of resend attempts for this registration.');

            return $this->redirectToRoute('app_register_verify_email');
        }

        $otpCode = $draftService->reissueOtp($draft);

        try {
            $notifier->sendRegistrationOtp($draft, $otpCode);
            $this->addFlash('success', 'A new verification code has been sent to your email address.');
        } catch (\Throwable) {
            $this->addFlash('danger', 'We could not send a new verification code right now. Please try again shortly.');
        }

        return $this->redirectToRoute('app_register_verify_email');
    }
}