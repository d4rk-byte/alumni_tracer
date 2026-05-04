<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\AccountSettingsService;
use App\Service\GoogleOnboardingService;
use App\Service\RegistrationValidationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/account')]
#[IsGranted('ROLE_USER')]
class AccountApiController extends AbstractController
{
    public function __construct(
        private AccountSettingsService $accountSettingsService,
        private GoogleOnboardingService $googleOnboardingService,
    ) {}

    #[Route('/settings', name: 'api_account_settings_show', methods: ['GET'])]
    public function settings(Request $request): JsonResponse
    {
        return $this->json(
            $this->accountSettingsService->getSettings($this->currentUser(), $this->baseUrl($request))
        );
    }

    #[Route('/settings', name: 'api_account_settings_update', methods: ['PATCH'])]
    public function updateSettings(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $result = $this->accountSettingsService->updateSettings(
            $this->currentUser(),
            is_array($payload) ? $payload : [],
            $this->baseUrl($request)
        );

        return $this->json($result, isset($result['errors']) ? 422 : 200);
    }

    #[Route('/photo', name: 'api_account_photo_update', methods: ['POST'])]
    public function updatePhoto(Request $request): JsonResponse
    {
        $result = $this->accountSettingsService->updatePhoto(
            $this->currentUser(),
            $request->files->get('photo'),
            $this->baseUrl($request)
        );

        return $this->json($result, isset($result['errors']) ? 422 : 200);
    }

    #[Route('/photo', name: 'api_account_photo_delete', methods: ['DELETE'])]
    public function removePhoto(Request $request): JsonResponse
    {
        return $this->json(
            $this->accountSettingsService->removePhoto($this->currentUser(), $this->baseUrl($request))
        );
    }

    #[Route('/google-onboarding', name: 'api_account_google_onboarding_show', methods: ['GET'])]
    public function googleOnboarding(): JsonResponse
    {
        $user = $this->currentUser();

        if (!$this->googleOnboardingService->hasAlumniMatchForOnboarding($user)) {
            return $this->json([
                'message' => $this->googleOnboardingService->onboardingBlockedMessage(),
            ], 409);
        }

        return $this->json([
            'needsOnboarding' => $this->googleOnboardingService->needsOnboarding($user),
            ...$this->googleOnboardingService->buildFrontendContext($user),
        ]);
    }

    #[Route('/google-onboarding', name: 'api_account_google_onboarding_complete', methods: ['PATCH'])]
    public function completeGoogleOnboarding(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $user = $this->currentUser();

        if (!$this->googleOnboardingService->hasAlumniMatchForOnboarding($user)) {
            return $this->json([
                'message' => $this->googleOnboardingService->onboardingBlockedMessage(),
                'errors' => [
                    'form' => $this->googleOnboardingService->onboardingBlockedMessage(),
                ],
            ], 409);
        }

        try {
            $user = $this->googleOnboardingService->completeOnboarding(
                $user,
                is_array($payload) ? $payload : [],
            );

            return $this->json([
                'message' => 'Your Google profile is complete.',
                ...$this->accountSettingsService->getSettings($user, $this->baseUrl($request)),
            ]);
        } catch (RegistrationValidationException $exception) {
            return $this->json([
                'message' => 'Please complete the required Google account details.',
                'errors' => $exception->getFieldErrors(),
            ], 422);
        }
    }

    #[Route('/password', name: 'api_account_password_update', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $result = $this->accountSettingsService->changePassword(
            $this->currentUser(),
            is_array($payload) ? $payload : [],
            $this->baseUrl($request)
        );

        return $this->json($result, isset($result['errors']) ? 422 : 200);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated account is required.');
        }

        return $user;
    }

    private function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }
}
