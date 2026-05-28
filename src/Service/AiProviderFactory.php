<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Repository\AppSettingRepository;

class AiProviderFactory implements AiProviderInterface
{
    public const SETTING_PROVIDER = 'ai_provider';
    public const PROVIDER_CLAUDE  = 'claude';
    public const PROVIDER_GEMINI  = 'gemini';

    public function __construct(
        private readonly AnthropicService    $claude,
        private readonly GeminiService       $gemini,
        private readonly AppSettingRepository $settings,
    ) {}

    public function activeProvider(): string
    {
        return $this->settings->get(self::SETTING_PROVIDER) ?? self::PROVIDER_CLAUDE;
    }

    private function active(): AiProviderInterface
    {
        return $this->activeProvider() === self::PROVIDER_GEMINI ? $this->gemini : $this->claude;
    }

    public function loadPrompt(string $name, array $variables = []): string
    {
        return $this->active()->loadPrompt($name, $variables);
    }

    public function call(AiReport $report, string $prompt, string $model = self::MODEL_FULL): ?array
    {
        return $this->active()->call($report, $prompt, $model);
    }

    public function callRaw(string $prompt, string $model = self::MODEL_BALANCED, int $maxTokens = 4096): ?array
    {
        return $this->active()->callRaw($prompt, $model, $maxTokens);
    }

    public function callText(string $prompt, string $model = self::MODEL_FAST, int $maxTokens = 1024): string
    {
        return $this->active()->callText($prompt, $model, $maxTokens);
    }

    public function callVision(string $imageUrl, string $prompt, string $model = self::MODEL_FAST): ?array
    {
        return $this->active()->callVision($imageUrl, $prompt, $model);
    }

    public function getAvailableModels(bool $forceRefresh = false): array
    {
        return $this->active()->getAvailableModels($forceRefresh);
    }
}
