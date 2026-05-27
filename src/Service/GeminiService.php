<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Enum\AiReportStatus;
use App\Repository\AppSettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeminiService implements AiProviderInterface
{
    public const SETTING_API_KEY = 'gemini_api_key';

    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    private const TIER_MAP = [
        AiProviderInterface::TIER_FAST     => 'gemini-2.0-flash',
        AiProviderInterface::TIER_BALANCED => 'gemini-1.5-pro',
        AiProviderInterface::TIER_FULL     => 'gemini-2.5-pro',
    ];

    // Exact pricing per 1M tokens (input / output) in USD — only stable, non-preview model IDs
    // Preview/versioned models (e.g. gemini-2.5-flash-preview-05-20) are intentionally omitted;
    // their pricing changes — check Google AI Studio for current rates.
    private const PRICING = [
        'gemini-2.0-flash'      => ['in' => 0.10,   'out' => 0.40],
        'gemini-2.0-flash-lite' => ['in' => 0.075,  'out' => 0.30],
        'gemini-2.0-flash-exp'  => ['in' => 0.00,   'out' => 0.00],
        'gemini-1.5-flash'      => ['in' => 0.075,  'out' => 0.30],
        'gemini-1.5-flash-8b'   => ['in' => 0.0375, 'out' => 0.15],
        'gemini-1.5-pro'        => ['in' => 1.25,   'out' => 5.00],
    ];

    // Hardcoded image generation models (use /predict, not /generateContent — not in API model list)
    private const IMAGE_MODELS = [
        ['id' => 'imagen-3.0-generate-001',      'name' => 'Imagen 3',      'tier' => null, 'type' => 'image', 'pricing' => ['label' => '$0.04/image']],
        ['id' => 'imagen-3.0-fast-generate-001', 'name' => 'Imagen 3 Fast', 'tier' => null, 'type' => 'image', 'pricing' => ['label' => '$0.02/image']],
    ];

    private const MAX_TOKENS = 2048;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AppSettingRepository $settingRepo,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
    ) {}

    public function loadPrompt(string $name, array $variables = []): string
    {
        $path = __DIR__ . '/../../config/prompts/' . $name . '.txt';
        if (!file_exists($path)) {
            throw new \RuntimeException("Prompt file not found: {$name}.txt");
        }

        $template = file_get_contents($path);
        foreach ($variables as $key => $value) {
            $template = str_replace('{{' . $key . '}}', (string) $value, $template);
        }
        return $template;
    }

    public function call(AiReport $report, string $prompt, string $model = self::MODEL_FULL): ?array
    {
        $startTime     = microtime(true);
        $resolvedModel = $this->resolveModel($model);

        try {
            $data = $this->request($resolvedModel, [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ], self::MAX_TOKENS);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            $report->setModelVersion($resolvedModel);
            $report->setTokensInput($data['usageMetadata']['promptTokenCount'] ?? 0);
            $report->setTokensOutput($data['usageMetadata']['candidatesTokenCount'] ?? 0);
            $report->setDurationMs($durationMs);

            $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = $this->parseJson($text);

            if (!is_array($parsed)) {
                $this->logger->error('Gemini call: invalid JSON', ['response' => substr($text, 0, 500)]);
                $report->setStatus(AiReportStatus::Failed);
                return null;
            }

            $report->setPayload($parsed);
            $report->setStatus(AiReportStatus::Done);

            $this->logger->info('Gemini call successful', [
                'type'        => $report->getType()->value,
                'model'       => $resolvedModel,
                'duration_ms' => $durationMs,
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $report->setStatus(AiReportStatus::Failed);
            $report->setDurationMs($durationMs);
            $report->setModelVersion($resolvedModel);
            $this->logger->error('Gemini call failed', ['error' => $e->getMessage(), 'model' => $resolvedModel]);
            return null;
        }
    }

    public function callRaw(string $prompt, string $model = self::MODEL_BALANCED, int $maxTokens = 4096): ?array
    {
        $resolvedModel = $this->resolveModel($model);
        $startTime     = microtime(true);

        try {
            $data   = $this->request($resolvedModel, [
                ['role' => 'user', 'parts' => [['text' => $prompt]]],
            ], $maxTokens);

            $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = $this->parseJson($text);

            if (!is_array($parsed)) {
                $this->logger->error('Gemini callRaw: invalid JSON', ['response' => substr($text, 0, 500)]);
                return null;
            }

            $this->logger->info('Gemini callRaw successful', [
                'model'       => $resolvedModel,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $this->logger->error('Gemini callRaw failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function callVision(string $imageUrl, string $prompt, string $model = self::MODEL_FAST): ?array
    {
        $resolvedModel = $this->resolveModel($model);
        $startTime     = microtime(true);

        try {
            $imageData = @file_get_contents($imageUrl);
            if ($imageData === false) {
                $this->logger->error('Gemini callVision: failed to fetch image', ['url' => $imageUrl]);
                return null;
            }

            $mediaType = str_starts_with($imageData, "\x89PNG") ? 'image/png' : 'image/jpeg';

            $data = $this->request($resolvedModel, [[
                'role'  => 'user',
                'parts' => [
                    ['inlineData' => ['mimeType' => $mediaType, 'data' => base64_encode($imageData)]],
                    ['text' => $prompt],
                ],
            ]], 512);

            $text   = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $parsed = $this->parseJson($text);

            if (!is_array($parsed)) {
                $this->logger->error('Gemini callVision: invalid JSON', ['response' => substr($text, 0, 500)]);
                return null;
            }

            $this->logger->info('Gemini callVision successful', [
                'model'       => $resolvedModel,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $this->logger->error('Gemini callVision failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private function resolveModel(string $model): string
    {
        // Tier alias → real model; otherwise pass-through (specific model ID)
        return self::TIER_MAP[$model] ?? $model;
    }

    public function getAvailableModels(bool $forceRefresh = false): array
    {
        $apiKey = $this->settingRepo->get(self::SETTING_API_KEY);
        if (!$apiKey) {
            return $this->defaultModels();
        }

        if ($forceRefresh) {
            $this->cache->delete('gemini_models');
        }

        $models = $this->cache->get('gemini_models', function (ItemInterface $item) use ($apiKey) {
            $item->expiresAfter(86400);
            try {
                $response = $this->httpClient->request('GET',
                    'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey)
                );
                $data   = $response->toArray();
                $models = [];
                foreach ($data['models'] ?? [] as $m) {
                    $methods = $m['supportedGenerationMethods'] ?? [];
                    if (!in_array('generateContent', $methods, true)) continue;
                    $rawId = $m['name'] ?? '';
                    $id    = preg_replace('#^models/#', '', $rawId);
                    $name  = $m['displayName'] ?? $id;
                    $tier  = $this->detectTier($id);
                    $models[] = ['id' => $id, 'name' => $name, 'tier' => $tier, 'type' => $this->detectType($id), 'pricing' => $this->getPricing($id)];
                }
                usort($models, fn($a, $b) => $this->tierOrder($a['tier']) <=> $this->tierOrder($b['tier']));
                // Append hardcoded image generation models
                foreach (self::IMAGE_MODELS as $im) {
                    $models[] = $im;
                }
                if (empty($models)) {
                    $item->expiresAfter(0);
                    return [];
                }
                return $models;
            } catch (\Throwable $e) {
                $this->logger->warning('Could not fetch Gemini models: ' . $e->getMessage());
                $item->expiresAfter(0);
                return [];
            }
        });

        return $models ?: $this->defaultModels();
    }

    public function generateImage(string $prompt, string $model = 'imagen-3.0-generate-001'): ?string
    {
        $url = self::BASE_URL . $model . ':predict?key=' . urlencode($this->apiKey());

        $response = $this->httpClient->request('POST', $url, [
            'json'    => [
                'instances'  => [['prompt' => $prompt]],
                'parameters' => ['sampleCount' => 1, 'aspectRatio' => '16:9'],
            ],
            'timeout' => 30,
        ]);

        $data = $response->toArray();
        return $data['predictions'][0]['bytesBase64Encoded'] ?? null;
    }

    public function validateApiKey(string $key): bool
    {
        try {
            $response = $this->httpClient->request('GET',
                'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($key),
                ['timeout' => 6]
            );
            $data = $response->toArray();
            return !empty($data['models']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function clearModelsCache(): void
    {
        $this->cache->delete('gemini_models');
    }

    private function apiKey(): string
    {
        $key = $this->settingRepo->get(self::SETTING_API_KEY);
        if (!$key) {
            throw new \RuntimeException('Gemini API key not configured. Go to Admin > Paramètres.');
        }
        return $key;
    }

    private function request(string $model, array $contents, int $maxOutputTokens): array
    {
        $url = self::BASE_URL . $model . ':generateContent?key=' . urlencode($this->apiKey());

        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'contents'       => $contents,
                'generationConfig' => [
                    'maxOutputTokens'  => $maxOutputTokens,
                    'responseMimeType' => 'application/json',
                ],
            ],
        ]);

        return $response->toArray();
    }

    private function parseJson(string $text): mixed
    {
        // Strip possible markdown fences if responseMimeType wasn't respected
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/i', '', $text);
        return json_decode($text, true);
    }

    private function getPricing(string $id): ?array
    {
        // Only return exact-match pricing — no pattern guessing to avoid showing wrong prices
        return self::PRICING[$id] ?? null;
    }

    private function detectType(string $id): string
    {
        $lower = strtolower($id);
        if (str_contains($lower, 'imagen')) return 'image';
        if (str_contains($lower, 'embedding') || str_contains($lower, 'retrieval')) return 'embedding';
        return 'text';
    }

    private function detectTier(string $id): ?string
    {
        $id = strtolower($id);
        if (str_contains($id, 'flash'))  return AiProviderInterface::TIER_FAST;
        if (str_contains($id, 'pro'))    return AiProviderInterface::TIER_BALANCED;
        if (str_contains($id, 'ultra'))  return AiProviderInterface::TIER_FULL;
        return null;
    }

    private function tierOrder(?string $tier): int
    {
        return match($tier) {
            AiProviderInterface::TIER_FAST     => 0,
            AiProviderInterface::TIER_BALANCED => 1,
            AiProviderInterface::TIER_FULL     => 2,
            default                            => 3,
        };
    }

    private function defaultModels(): array
    {
        return [
            ['id' => 'fast',     'name' => 'Fast (Flash)',   'tier' => AiProviderInterface::TIER_FAST,     'pricing' => ['in' => 0.10, 'out' => 0.40]],
            ['id' => 'balanced', 'name' => 'Balanced (Pro)', 'tier' => AiProviderInterface::TIER_BALANCED, 'pricing' => ['in' => 1.25, 'out' => 5.00]],
            ['id' => 'full',     'name' => 'Full (2.5 Pro)', 'tier' => AiProviderInterface::TIER_FULL,     'pricing' => ['in' => 1.25, 'out' => 10.00]],
        ];
    }
}
