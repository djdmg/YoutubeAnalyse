<?php

namespace App\MessageHandler;

use App\Entity\AiReport;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Message\GenerateGoalSuggestionsMessage;
use App\Service\AiProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class GenerateGoalSuggestionsHandler
{
    private const SCHEMA = [
        'type'  => 'array',
        'items' => [
            'type'       => 'object',
            'properties' => [
                'label'       => ['type' => 'string'],
                'type'        => ['type' => 'string', 'enum' => ['subscribers', 'views', 'watch_time']],
                'targetValue' => ['type' => 'integer'],
                'deadline'    => ['type' => 'string'],
            ],
            'required' => ['label', 'type', 'targetValue', 'deadline'],
        ],
    ];

    public function __construct(
        private readonly AiProviderFactory      $ai,
        private readonly CacheInterface         $cache,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(GenerateGoalSuggestionsMessage $msg): void
    {
        $cacheKey = 'goal_suggestions_' . $msg->jobId;

        $report = new AiReport();
        $report->setType(AiReportType::GoalSuggestions);
        $report->setStatus(AiReportStatus::Pending);

        try {
            $prompt = $this->buildPrompt($msg);

            // Primary: native JSON mode (Gemini responseMimeType, Claude tool_use)
            $items = $this->ai->callJson($prompt, self::SCHEMA, $msg->model, 600, $report);

            // Fallback: plain text call + robust extraction (handles any stray prose/fences)
            if (!is_array($items)) {
                $rawText = $this->ai->callText($prompt, $msg->model, 600);
                $items   = $this->extractJsonArray($rawText);
            }

            if (!is_array($items)) {
                throw new \RuntimeException('Impossible d\'obtenir un tableau JSON valide après deux tentatives.');
            }

            $suggestions = $this->validateItems($items);

            $report->setStatus(AiReportStatus::Done);
            $report->setPayload(['count' => count($suggestions)]);
            $this->em->persist($report);
            $this->em->flush();

            $this->store($cacheKey, ['status' => 'done', 'suggestions' => $suggestions]);

        } catch (\Throwable $e) {
            $report->setStatus(AiReportStatus::Failed);
            $this->em->persist($report);
            $this->em->flush();
            $this->store($cacheKey, ['status' => 'error', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function buildPrompt(GenerateGoalSuggestionsMessage $msg): string
    {
        return <<<PROMPT
You are a YouTube growth strategist. Analyse these channel metrics and return 3 to 5 SMART goals as a JSON array.

Today: {$msg->today}
Subscribers: {$msg->subscribers}
Views last 30 days: {$msg->views30}
Views last 7 days: {$msg->views7}
Watch time last 30 days: {$msg->watchTime30} minutes
Average CTR: {$msg->avgCtr}%
Existing goals: {$msg->existingGoals}

Rules:
- Ambitious yet realistic (achievable in 1–6 months)
- Do NOT duplicate existing goals
- Mix types: subscribers, views, watch_time
- Deadlines in YYYY-MM-DD, between 1 and 6 months from today
- Labels in French, ≤ 50 characters, motivating

Return ONLY a JSON array — no prose, no markdown, no explanation:
[
  {"label":"string (French, ≤50 chars)","type":"subscribers|views|watch_time","targetValue":1234,"deadline":"YYYY-MM-DD"},
  ...
]
PROMPT;
    }

    /** Strips markdown fences, finds the first [...] block, and JSON-decodes it. */
    private function extractJsonArray(string $text): mixed
    {
        // Strip ```json ... ``` fences
        $text = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $text = preg_replace('/\s*```\s*$/im', '', $text);

        // Find the first complete [...] block (handles nested arrays/objects)
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $json = substr($text, $start, $end - $start + 1);
        return json_decode($json, true);
    }

    /** Filters and normalises raw AI items. */
    private function validateItems(array $items): array
    {
        $suggestions = [];
        foreach ($items as $s) {
            if (!is_array($s)) continue;
            if (!isset($s['label'], $s['type'], $s['targetValue'])) continue;
            if (!in_array($s['type'], ['subscribers', 'views', 'watch_time'], true)) continue;
            $tv = (int) $s['targetValue'];
            if ($tv <= 0) continue;
            $suggestions[] = [
                'label'       => mb_substr((string) $s['label'], 0, 100),
                'type'        => $s['type'],
                'targetValue' => $tv,
                'deadline'    => isset($s['deadline']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s['deadline'])
                                    ? $s['deadline'] : null,
            ];
        }
        return $suggestions;
    }

    private function store(string $key, array $data): void
    {
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($data) {
            $item->expiresAfter(600);
            return $data;
        });
    }
}
