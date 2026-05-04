<?php

namespace App\Entity;

use App\Repository\SystemSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SystemSettingRepository::class)]
#[ORM\Table(name: 'system_setting')]
#[ORM\UniqueConstraint(name: 'UNIQ_SYSTEM_SETTING_KEY_NAME', fields: ['keyName'])]
class SystemSetting
{
    public const PUBLIC_SIGNUP_ENABLED = 'public_signup_enabled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120, unique: true)]
    private string $keyName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $value = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKeyName(): string
    {
        return $this->keyName;
    }

    public function setKeyName(string $keyName): static
    {
        $this->keyName = $keyName;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): static
    {
        $this->value = $value;

        return $this;
    }
}
