<?php

namespace App\Controller;

use App\Form\GoogleOnboardingType;
use App\Service\GoogleOnboardingService;
use App\Service\RegistrationValidationException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GoogleOnboardingController extends AbstractController
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
    ) {
    }

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

        if ($request->isMethod('GET')) {
            return $this->redirectToFrontendGoogleCallback([
                'token' => $this->jwtManager->create($user),
                'onboarding' => '1',
            ]);
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

    /**
     * @param array<string, string> $params
     */
    private function redirectToFrontendGoogleCallback(array $params): RedirectResponse
    {
        $frontendUrl = trim((string) ($_ENV['FRONTEND_URL'] ?? $_SERVER['FRONTEND_URL'] ?? ''));

        if ($frontendUrl === '') {
            $frontendUrl = 'http://localhost:3000';
        }

        return $this->redirect(
            rtrim($frontendUrl, '/') . '/auth/google/callback?' . http_build_query($params, '', '&', \PHP_QUERY_RFC3986)
        );
    }
}
