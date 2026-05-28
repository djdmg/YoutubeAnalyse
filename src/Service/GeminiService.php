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
    public const SETTING_API_KEY          = 'gemini_api_key';
    public const SETTING_THUMBNAIL_MODEL  = 'thumbnail_model';
    public const SETTING_PROMPT_MODEL     = 'thumbnail_prompt_model';

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

    public function callRawText(string $prompt, string $model = self::MODEL_FAST, int $maxTokens = 512, float $temperature = 1.0): string
    {
        $resolvedModel = $this->resolveModel($model);
        $url      = self::BASE_URL . $resolvedModel . ':generateContent?key=' . urlencode($this->apiKey());
        $response = $this->httpClient->request('POST', $url, [
            'json' => [
                'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['maxOutputTokens' => $maxTokens, 'temperature' => $temperature],
            ],
            'timeout' => 30,
        ]);
        $data = $response->toArray();
        $text = trim($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
        if ($text === '') {
            $reason = $data['candidates'][0]['finishReason'] ?? ($data['promptFeedback']['blockReason'] ?? 'unknown');
            throw new \RuntimeException("Gemini returned empty text (reason: {$reason}).");
        }
        return $text;
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

    public function getImageModels(): array
    {
        $all = $this->getAvailableModels();
        return array_values(array_filter($all, fn($m) => ($m['type'] ?? 'text') === 'image'));
    }

    public function generateImage(string $prompt, string $model = 'imagen-3.0-generate-001'): ?string
    {
        // Imagen models use the /predict endpoint; Gemini native image-gen models use /generateContent
        if (str_contains(strtolower($model), 'imagen')) {
            return $this->generateImageWithPredict($prompt, $model);
        }
        return $this->generateImageWithGenerateContent($prompt, $model);
    }

    private function generateImageWithPredict(string $prompt, string $model): ?string
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

    private function generateImageWithGenerateContent(string $prompt, string $model): ?string
    {
        $url = self::BASE_URL . $model . ':generateContent?key=' . urlencode($this->apiKey());

        $response = $this->httpClient->request('POST', $url, [
            'json'    => [
                'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseModalities' => ['IMAGE', 'TEXT']],
            ],
            'timeout' => 120,
        ]);

        $data  = $response->toArray();

        // Log full response to help diagnose unexpected structures
        $this->logger->debug('generateImageWithGenerateContent response', [
            'model'          => $model,
            'finishReason'   => $data['candidates'][0]['finishReason'] ?? 'n/a',
            'parts_count'    => count($data['candidates'][0]['content']['parts'] ?? []),
            'parts_types'    => array_keys(array_merge(...array_map('array_keys', $data['candidates'][0]['content']['parts'] ?? [[]]))),
            'promptFeedback' => $data['promptFeedback'] ?? null,
        ]);

        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            if (isset($part['inlineData']['data'])) {
                return $part['inlineData']['data'];
            }
        }

        // Build a meaningful exception so the controller can show a useful message
        $finishReason = $data['candidates'][0]['finishReason'] ?? null;
        $blocked      = $data['promptFeedback']['blockReason'] ?? null;
        $textFallback = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($blocked) {
            throw new \RuntimeException("Prompt bloqué par le filtre de sécurité Gemini : {$blocked}.");
        }
        if ($finishReason && $finishReason !== 'STOP') {
            $hint = match($finishReason) {
                'IMAGE_OTHER'   => "Le modèle a refusé de générer l'image (raison inconnue). Essayez un prompt différent ou moins détaillé.",
                'SAFETY'        => "Le prompt a été bloqué par le filtre de sécurité. Reformulez sans termes sensibles.",
                'RECITATION'    => "Le contenu a été bloqué pour cause de récitation. Modifiez le prompt.",
                'MAX_TOKENS'    => "Le prompt est trop long. Raccourcissez-le.",
                default         => "Génération arrêtée (finishReason: {$finishReason}). Essayez un autre prompt.",
            };
            throw new \RuntimeException($hint);
        }
        if ($textFallback) {
            throw new \RuntimeException("Le modèle a répondu en texte au lieu d'une image : \"{$textFallback}\"");
        }

        throw new \RuntimeException("Aucune image reçue du modèle {$model}. Vérifiez que ce modèle supporte la génération d'images avec responseModalities.");
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
        if (str_contains($lower, 'imagen'))           return 'image';
        if (str_contains($lower, 'image-generation')) return 'image';
        if (str_contains($lower, 'image-preview'))    return 'image';
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
