<?php

namespace App\Service;

use App\Entity\AiReport;

interface AiProviderInterface
{
    // Provider-agnostic tiers
    const TIER_FAST     = 'fast';
    const TIER_BALANCED = 'balanced';
    const TIER_FULL     = 'full';

    // Aliases kept for call-site readability
    const MODEL_FAST     = self::TIER_FAST;
    const MODEL_BALANCED = self::TIER_BALANCED;
    const MODEL_FULL     = self::TIER_FULL;

    public function loadPrompt(string $name, array $variables = []): string;

    public function call(AiReport $report, string $prompt, string $model = self::MODEL_FULL): ?array;

    public function callRaw(string $prompt, string $model = self::MODEL_BALANCED, int $maxTokens = 4096): ?array;

    /** Returns the model's raw text response (no JSON parsing). Throws on failure. */
    public function callText(string $prompt, string $model = self::MODEL_FAST, int $maxTokens = 1024): string;

    public function callVision(string $imageUrl, string $prompt, string $model = self::MODEL_FAST): ?array;

    /**
     * Returns the list of models available for this provider.
     * Each entry: ['id' => string, 'name' => string, 'tier' => 'fast'|'balanced'|'full'|null]
     */
    public function getAvailableModels(bool $forceRefresh = false): array;
}
