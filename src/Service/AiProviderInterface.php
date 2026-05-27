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

    public function callVision(string $imageUrl, string $prompt, string $model = self::MODEL_FAST): ?array;
}
