<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\AlumniRegistrationService;
use App\Service\NotificationService;
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
        AlumniRegistrationService $registrationService,
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
                $registeredUser = $registrationService->register([
                    'email' => (string) $user->getEmail(),
                    'studentId' => (string) $user->getSchoolId(),
                    'firstName' => (string) $user->getFirstName(),
                    'lastName' => (string) $user->getLastName(),
                    'plainPassword' => (string) $form->get('plainPassword')->getData(),
                    'yearGraduated' => $form->get('yearGraduated')->getData(),
                    'dpaConsent' => (bool) $form->get('dataPrivacyConsent')->getData(),
                ], 'pending');

                try {
                    $notifier->notifyNewRegistration($registeredUser);
                } catch (\Throwable) {
                    // Email delivery failure should not block registration
                }

                $this->addFlash('success', 'Registration submitted successfully. Your account is awaiting approval. You can sign in after an administrator activates it.');

                return $this->redirectToRoute('app_login');
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
        ]);
    }
}
