<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use Anthropic\Client;
use Psr\Log\LoggerInterface;

class AnthropicService
{
    // Modèles par usage — du plus léger au plus puissant
    public const MODEL_FAST     = 'claude-haiku-4-5-20251001';   // Rapide, économique (prédictions)
    public const MODEL_BALANCED = 'claude-sonnet-4-6';            // Équilibré (optimisation titres)
    public const MODEL_FULL     = 'claude-sonnet-4-20250514';     // Défaut (analyses complexes)

    private const MAX_TOKENS = 2048;

    private readonly Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client(apiKey: $this->apiKey);
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

        try {
            $response = $this->client->messages->create(
                maxTokens: $maxTokens,
                messages:  [['role' => 'user', 'content' => $prompt]],
                model:     $model,
            );

            $text   = $response->content[0]->text ?? '';
            $parsed = json_decode($text, true);

            if (!is_array($parsed)) {
                $this->logger->error('Claude callRaw: invalid JSON', ['response' => substr($text, 0, 500)]);
                return null;
            }

            $this->logger->info('Claude callRaw successful', [
                'model'          => $model,
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
        $startTime = microtime(true);

        try {
            $response = $this->client->messages->create(
                maxTokens: self::MAX_TOKENS,
                messages:  [['role' => 'user', 'content' => $prompt]],
                model:     $model,
            );

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $text = $response->content[0]->text ?? '';

            $report->setModelVersion($model);
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
            $report->setModelVersion($model);

            $this->logger->error('Claude call failed', [
                'type'        => $report->getType()->value,
                'model'       => $model,
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
        $startTime = microtime(true);

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
                model:     $model,
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
                'model'       => $model,
                'duration_ms' => (int) ((microtime(true) - $startTime) * 1000),
            ]);

            return $parsed;

        } catch (\Throwable $e) {
            $this->logger->error('callVision failed', ['error' => $e->getMessage(), 'url' => $imageUrl]);
            return null;
        }
    }
}
