<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Service\NotificationService;
use App\Service\SystemSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/system-settings')]
#[IsGranted('ROLE_ADMIN')]
class SystemSettingsApiController extends AbstractController
{
    public function __construct(
        private SystemSettingsService $systemSettings,
        private NotificationService $notificationService,
    ) {}

    #[Route('', name: 'api_admin_system_settings_show', methods: ['GET'])]
    public function show(): JsonResponse
    {
        return $this->json(['item' => $this->systemSettings->serialize()]);
    }

    #[Route('', name: 'api_admin_system_settings_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['message' => 'Settings data is required.'], 422);
        }

        if (array_key_exists('publicSignupEnabled', $payload)) {
            $before = $this->systemSettings->isPublicSignupEnabled();
            $after = (bool) $payload['publicSignupEnabled'];
            $this->systemSettings->setPublicSignupEnabled($after);

            if ($before !== $after) {
                $this->notificationService->createAdminNotification(
                    'system.settings_changed',
                    'System setting changed',
                    sprintf('Public alumni signup was turned %s.', $after ? 'on' : 'off'),
                    Notification::SEVERITY_WARNING,
                    '/system-setup',
                    'SystemSetting',
                    null,
                );
            }
        }

        return $this->json(['item' => $this->systemSettings->serialize()]);
    }
}
