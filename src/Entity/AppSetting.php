<?php

namespace App\Entity;

use App\Repository\AppSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AppSettingRepository::class)]
#[ORM\Table(name: 'app_settings')]
class AppSetting
{
    #[ORM\Id]
    #[ORM\Column(name: 'setting_key', length: 100)]
    private string $key;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $value;

    public function __construct(string $key, ?string $value = null)
    {
        $this->key   = $key;
        $this->value = $value;
    }

    public function getKey(): string { return $this->key; }
    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): static { $this->value = $value; return $this; }
}
