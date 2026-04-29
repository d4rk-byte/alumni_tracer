<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\NotificationService;
use App\Service\RegistrationDraftService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        RegistrationDraftService $draftService,
        NotificationService $notifier,
    ): Response {
        // If already logged in, redirect to home
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $draftResult = $draftService->createManualDraft([
                    'email' => (string) $user->getEmail(),
                    'studentId' => (string) $user->getSchoolId(),
                    'firstName' => (string) $user->getFirstName(),
                    'lastName' => (string) $user->getLastName(),
                    'plainPassword' => (string) $form->get('plainPassword')->getData(),
                    'yearGraduated' => $form->get('yearGraduated')->getData(),
                    'dpaConsent' => (bool) $form->get('dataPrivacyConsent')->getData(),
                ]);

                $request->getSession()->set(RegistrationDraftService::SESSION_KEY, $draftResult['draft']->getId());

                try {
                    $notifier->sendRegistrationOtp($draftResult['draft'], $draftResult['otpCode']);
                    $this->addFlash('success', 'We sent a verification code to your email. Enter it below to continue.');
                } catch (\Throwable) {
                    $this->addFlash('warning', 'We saved your registration draft, but the verification code could not be sent yet. Use resend on the next screen.');
                }

                return $this->redirectToRoute('app_register_verify_email');
            } catch (\App\Service\RegistrationValidationException $exception) {
                foreach ($exception->getFieldErrors() as $field => $message) {
                    if ($field === 'form' || !$form->has($field)) {
                        $form->addError(new FormError($message));

                        continue;
                    }

                    $form->get($field)->addError(new FormError($message));
                }
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ], new Response(status: $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }
}
