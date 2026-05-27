<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use Anthropic\Client;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AnthropicService implements AiProviderInterface
{
    // Tier → actual Claude model name
    private const TIER_MAP = [
        AiProviderInterface::TIER_FAST     => 'claude-haiku-4-5-20251001',
        AiProviderInterface::TIER_BALANCED => 'claude-sonnet-4-6',
        AiProviderInterface::TIER_FULL     => 'claude-sonnet-4-20250514',
    ];

    // Pricing per 1M tokens (input / output) in USD
    private const PRICING = [
        'claude-haiku-4-5-20251001' => ['in' => 0.80,  'out' => 4.00],
        'claude-haiku-4-5'          => ['in' => 0.80,  'out' => 4.00],
        'claude-sonnet-4-6'         => ['in' => 3.00,  'out' => 15.00],
        'claude-sonnet-4-20250514'  => ['in' => 3.00,  'out' => 15.00],
        'claude-opus-4-5'           => ['in' => 15.00, 'out' => 75.00],
        'claude-opus-4-7'           => ['in' => 15.00, 'out' => 75.00],
    ];

    // Keep for legacy call-sites that might still use these directly
    public const MODEL_FAST     = AiProviderInterface::TIER_FAST;
    public const MODEL_BALANCED = AiProviderInterface::TIER_BALANCED;
    public const MODEL_FULL     = AiProviderInterface::TIER_FULL;

    private const MAX_TOKENS = 2048;

    private readonly Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
        $this->client = new Client(apiKey: $this->apiKey);
    }

    private function resolveModel(string $model): string
    {
        // Tier alias → real model; otherwise pass-through (specific model ID)
        return self::TIER_MAP[$model] ?? $model;
    }

    public function getAvailableModels(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            $this->cache->delete('anthropic_models');
        }
        return $this->cache->get('anthropic_models', function (ItemInterface $item) {
            $item->expiresAfter(86400);
            try {
                $response = $this->httpClient->request('GET', 'https://api.anthropic.com/v1/models', [
                    'headers' => [
                        'x-api-key'         => $this->apiKey,
                        'anthropic-version' => '2023-06-01',
                    ],
                ]);
                $data   = $response->toArray();
                $models = [];
                foreach ($data['data'] ?? [] as $m) {
                    $id   = $m['id'] ?? '';
                    $name = $m['display_name'] ?? $id;
                    $tier = $this->detectTier($id);
                    $models[] = ['id' => $id, 'name' => $name, 'tier' => $tier, 'pricing' => self::PRICING[$id] ?? null];
                }
                // Sort: fast first, then balanced, then full, then null
                usort($models, fn($a, $b) => $this->tierOrder($a['tier']) <=> $this->tierOrder($b['tier']));
                return $models;
            } catch (\Throwable $e) {
                $this->logger->warning('Could not fetch Anthropic models: ' . $e->getMessage());
                return $this->defaultModels();
            }
        });
    }

    /**
     * Loads a prompt template from config/prompts/ and replaces {{placeholders}}.
     */
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

    /**
     * Calls Claude without any AiReport entity. Returns parsed JSON or null.
     */
    public function callRaw(string $prompt, string $model = self::MODEL_BALANCED, int $maxTokens = 4096): ?array
    {
        $startTime = microtime(true);

        $resolvedModel = $this->resolveModel($model);

        try {
            $response = $this->client->messages->create(
                maxTokens: $maxTokens,
                messages:  [['role' => 'user', 'content' => $prompt]],
                model:     $resolvedModel,
            );

            $text   = $response->content[0]->text ?? '';
            $parsed = json_decode($text, true);

            if (!is_array($parsed)) {
                $this->logger->error('Claude callRaw: invalid JSON', ['response' => substr($text, 0, 500)]);
                return null;
            }

            $this->logger->info('Claude callRaw successful', [
                'model'          => $resolvedModel,
                'tokens_input'   => $response->usage->inputTokens ?? 0,
                'tokens_output'  => $response->usage->outputTokens ?? 0,
                'duration_ms'    => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $this->logger->error('Claude callRaw failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Calls Claude with the given prompt, fills the AiReport entity, and returns parsed payload.
     * Returns null if Claude returns invalid JSON.
     */
    public function call(AiReport $report, string $prompt, string $model = self::MODEL_FULL): ?array
    {
        $startTime     = microtime(true);
        $resolvedModel = $this->resolveModel($model);

        try {
            $response = $this->client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages:  [['role' => 'user', 'content' => $prompt]],
                model:     $resolvedModel,
            );

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $text = $response->content[0]->text ?? '';

            $report->setModelVersion($resolvedModel);
            $report->setTokensInput($response->usage->inputTokens ?? 0);
            $report->setTokensOutput($response->usage->outputTokens ?? 0);
            $report->setDurationMs($durationMs);

            $parsed = json_decode($text, true);
            if (!is_array($parsed)) {
                $this->logger->error('Claude returned invalid JSON', [
                    'type'     => $report->getType()->value,
                    'response' => substr($text, 0, 500),
                ]);
                $report->setStatus(AiReportStatus::Failed);
                return null;
            }

            $report->setPayload($parsed);
            $report->setStatus(AiReportStatus::Done);

            $this->logger->info('Claude call successful', [
                'type'           => $report->getType()->value,
                'model'          => $resolvedModel,
                'tokens_input'   => $response->usage->inputTokens ?? 0,
                'tokens_output'  => $response->usage->outputTokens ?? 0,
                'duration_ms'    => $durationMs,
                'status'         => 'done',
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $report->setStatus(AiReportStatus::Failed);
            $report->setDurationMs($durationMs);
            $report->setModelVersion($resolvedModel);

            $this->logger->error('Claude call failed', [
                'type'        => $report->getType()->value,
                'model'       => $resolvedModel,
                'error'       => $e->getMessage(),
                'duration_ms' => $durationMs,
                'status'      => 'failed',
            ]);
            return null;
        }
    }

    /**
     * Calls Claude Vision with an image URL and prompt. Returns parsed JSON or null.
     */
    public function callVision(string $imageUrl, string $prompt, string $model = self::MODEL_FAST): ?array
    {
        $startTime     = microtime(true);
        $resolvedModel = $this->resolveModel($model);

        try {
            $imageData = @file_get_contents($imageUrl);
            if ($imageData === false) {
                $this->logger->error('callVision: failed to fetch image', ['url' => $imageUrl]);
                return null;
            }

            $base64    = base64_encode($imageData);
            $mediaType = 'image/jpeg';
            if (str_starts_with($imageData, "\x89PNG")) {
                $mediaType = 'image/png';
            }

            $response = $this->client->messages->create(
                maxTokens: 512,
                model:     $resolvedModel,
                messages:  [[
                    'role'    => 'user',
                    'content' => [
                        [
                            'type'   => 'image',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $mediaType,
                                'data'       => $base64,
                            ],
                        ],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
            );

            $text   = $response->content[0]->text ?? '';
            $parsed = json_decode($text, true);

            if (!is_array($parsed)) {
                $this->logger->error('callVision: invalid JSON', ['response' => substr($text, 0, 500)]);
                return null;
            }

            $this->logger->info('callVision successful', [
                'model'       => $resolvedModel,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $this->logger->error('callVision failed', ['error' => $e->getMessage(), 'url' => $imageUrl]);
            return null;
        }
    }

    private function detectTier(string $id): ?string
    {
        $id = strtolower($id);
        if (str_contains($id, 'haiku'))  return AiProviderInterface::TIER_FAST;
        if (str_contains($id, 'sonnet')) return AiProviderInterface::TIER_BALANCED;
        if (str_contains($id, 'opus'))   return AiProviderInterface::TIER_FULL;
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
            ['id' => 'fast',     'name' => 'Fast (Haiku)',      'tier' => AiProviderInterface::TIER_FAST,     'pricing' => ['in' => 0.80,  'out' => 4.00]],
            ['id' => 'balanced', 'name' => 'Balanced (Sonnet)', 'tier' => AiProviderInterface::TIER_BALANCED, 'pricing' => ['in' => 3.00,  'out' => 15.00]],
            ['id' => 'full',     'name' => 'Full (Sonnet)',     'tier' => AiProviderInterface::TIER_FULL,     'pricing' => ['in' => 3.00,  'out' => 15.00]],
        ];
    }
}
