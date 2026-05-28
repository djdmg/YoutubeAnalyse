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
    public function __construct(
        private readonly AiProviderFactory     $ai,
        private readonly CacheInterface        $cache,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(GenerateGoalSuggestionsMessage $msg): void
    {
        $cacheKey = 'goal_suggestions_' . $msg->jobId;

        $report = new AiReport();
        $report->setType(AiReportType::GoalSuggestions);
        $report->setStatus(AiReportStatus::Pending);

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
PROMPT;

            $schema = [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'label'       => ['type' => 'string', 'description' => 'French label, max 50 chars'],
                        'type'        => ['type' => 'string', 'enum' => ['subscribers', 'views', 'watch_time']],
                        'targetValue' => ['type' => 'integer'],
                        'deadline'    => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    ],
                    'required' => ['label', 'type', 'targetValue', 'deadline'],
                ],
            ];

            $items = $this->ai->callJson($prompt, $schema, $msg->model, 600, $report);

            if (!is_array($items)) {
                throw new \RuntimeException('AI returned null or non-array for goal suggestions.');
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

    private function store(string $key, array $data): void
    {
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($data) {
            $item->expiresAfter(600);
            return $data;
        });
    }
}
