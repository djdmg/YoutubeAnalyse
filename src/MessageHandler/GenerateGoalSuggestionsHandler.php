<?php

namespace App\MessageHandler;

use App\Message\GenerateGoalSuggestionsMessage;
use App\Service\AiProviderFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class GenerateGoalSuggestionsHandler
{
    public function __construct(
        private readonly AiProviderFactory $ai,
        private readonly CacheInterface    $cache,
    ) {}

    public function __invoke(GenerateGoalSuggestionsMessage $msg): void
    {
        $cacheKey = 'goal_suggestions_' . $msg->jobId;

        try {
            $prompt = <<<PROMPT
You are a YouTube growth strategist. Analyse these channel metrics and suggest 3 to 5 SMART goals.

Today: {$msg->today}
Current subscribers: {$msg->subscribers}
Views last 30 days: {$msg->views30}
Views last 7 days: {$msg->views7}
Watch time last 30 days: {$msg->watchTime30} minutes
Average CTR: {$msg->avgCtr}%
Existing active goals: {$msg->existingGoals}

Rules:
- Goals must be ambitious yet realistic (achievable in 1–6 months)
- Do NOT suggest goals already covered by existing active goals
- Vary the types: mix subscribers, views, and watch_time goals
- Deadlines must be in YYYY-MM-DD format, between 1 and 6 months from today
- Label must be in French, concise (max 50 chars), motivating

OUTPUT FORMAT — respond with ONLY a raw JSON array matching this schema exactly.
Do NOT use markdown code fences. Do NOT add any explanation. Start with [ and end with ].

Schema:
[
  {"label": "string (French, max 50 chars)", "type": "subscribers|views|watch_time", "targetValue": 1234, "deadline": "YYYY-MM-DD"},
  ...
]

Example:
[
  {"label": "Atteindre 1 000 abonnés", "type": "subscribers", "targetValue": 1000, "deadline": "{$msg->today}"},
  {"label": "1 000 vues en 30 jours", "type": "views", "targetValue": 1000, "deadline": "{$msg->today}"}
]
PROMPT;

            $text = trim($this->ai->callText($prompt, $msg->model, 600));

            // Strip markdown fences if the model ignored instructions
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/i', '', $text);

            // Extract JSON array (handles any preamble the model may have added)
            if (preg_match('/\[[\s\S]*\]/u', $text, $m)) {
                $text = $m[0];
            }

            $items = json_decode($text, true);
            if (!is_array($items)) {
                throw new \RuntimeException('Invalid JSON from AI: ' . substr($text, 0, 300));
            }

            $suggestions = [];
            foreach ($items as $s) {
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

            $this->store($cacheKey, ['status' => 'done', 'suggestions' => $suggestions]);

        } catch (\Throwable $e) {
            $this->store($cacheKey, ['status' => 'error', 'message' => $e->getMessage()]);
            throw $e;
        }
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
