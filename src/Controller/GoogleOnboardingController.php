<?php

namespace App\Controller;

use App\Form\GoogleOnboardingType;
use App\Service\GoogleOnboardingService;
use App\Service\RegistrationValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleOnboardingController extends AbstractController
{
    #[Route('/connect/google/onboarding', name: 'app_google_onboarding', methods: ['GET', 'POST'])]
    public function onboard(Request $request, GoogleOnboardingService $googleOnboardingService): Response
    {
        $user = $this->getUser();

        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('app_login');
        }

        if (!$googleOnboardingService->needsOnboarding($user)) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(GoogleOnboardingType::class, $googleOnboardingService->buildInitialData($user));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $googleOnboardingService->completeOnboarding($user, $form->getData() ?? []);

                $this->addFlash('success', 'Your Google profile is complete. Welcome to the Alumni Tracker.');

                return $this->redirectToRoute('app_home');
            } catch (RegistrationValidationException $exception) {
                foreach ($exception->getFieldErrors() as $field => $message) {
                    if ($field === 'form' || !$form->has($field)) {
                        $form->addError(new FormError($message));

                        continue;
                    }

                    $form->get($field)->addError(new FormError($message));
                }
            }
        }

        return $this->render('security/google_onboarding.html.twig', [
            'onboardingForm' => $form,
        ], new Response(status: $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK));
    }
}