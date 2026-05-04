<?php

namespace App\Service;

use App\Entity\SystemSetting;
use App\Repository\SystemSettingRepository;
use Doctrine\ORM\EntityManagerInterface;

class SystemSettingsService
{
    public function __construct(
        private SystemSettingRepository $settingsRepository,
        private EntityManagerInterface $entityManager,
    ) {}

    public function isPublicSignupEnabled(): bool
    {
        return $this->getBoolean(SystemSetting::PUBLIC_SIGNUP_ENABLED, true);
    }

    public function setPublicSignupEnabled(bool $enabled): void
    {
        $this->set(SystemSetting::PUBLIC_SIGNUP_ENABLED, $enabled ? '1' : '0');
    }

    /**
     * @return array{publicSignupEnabled: bool}
     */
    public function serialize(): array
    {
        return [
            'publicSignupEnabled' => $this->isPublicSignupEnabled(),
        ];
    }

    private function getBoolean(string $key, bool $default): bool
    {
        $setting = $this->settingsRepository->findOneBy(['keyName' => $key]);

        if (!$setting instanceof SystemSetting || $setting->getValue() === null) {
            return $default;
        }

        return filter_var($setting->getValue(), FILTER_VALIDATE_BOOLEAN);
    }

    private function set(string $key, string $value): void
    {
        $setting = $this->settingsRepository->findOneBy(['keyName' => $key]);

        if (!$setting instanceof SystemSetting) {
            $setting = (new SystemSetting())->setKeyName($key);
            $this->entityManager->persist($setting);
        }

        $setting->setValue($value);
        $this->entityManager->flush();
    }
}
