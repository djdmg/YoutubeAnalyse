<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Enum\AiReportStatus;
use App\Repository\AppSettingRepository;
use Psr\Log\LoggerInterface;
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

    private const MAX_TOKENS = 2048;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly AppSettingRepository $settingRepo,
        private readonly LoggerInterface $logger,
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

    private function resolveModel(string $tier): string
    {
        return self::TIER_MAP[$tier] ?? self::TIER_MAP[AiProviderInterface::TIER_FULL];
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
}
