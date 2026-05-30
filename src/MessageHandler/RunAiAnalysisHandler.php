<?php

namespace App\MessageHandler;

use App\Enum\AiReportType;
use App\Message\RunAiAnalysisMessage;
use App\Repository\UserRepository;
use App\Service\AiAnalysisService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class RunAiAnalysisHandler
{
    public function __construct(
        private readonly UserRepository    $userRepo,
        private readonly AiAnalysisService $aiService,
        private readonly CacheInterface    $cache,
    ) {}

    public function __invoke(RunAiAnalysisMessage $message): void
    {
        $cacheKey = 'job_' . $message->jobId;

        $user = $this->userRepo->find($message->userId);
        if (!$user) {
            $this->storeResult($cacheKey, ['status' => 'error', 'message' => 'Utilisateur introuvable.']);
            return;
        }

        if ($message->force) {
            $this->aiService->setForce(true);
        }

        try {
            $type   = $message->type ? AiReportType::from($message->type) : null;
            $result = $type
                ? $this->aiService->analyzeType($user, $type)
                : $this->aiService->analyzeAll($user);

            $total = array_sum($result['counts'] ?? []);
            $this->storeResult($cacheKey, [
                'status'  => 'done',
                'message' => sprintf('%d analyse(s) générée(s).', $total),
                'counts'  => $result['counts'] ?? [],
            ]);
        } catch (\Throwable $e) {
            // Do NOT re-throw: re-throwing causes Messenger to retry the message
            // indefinitely (up to max_retries, then into the failed queue which
            // can be consumed again). Store the error and exit cleanly instead.
            $this->storeResult($cacheKey, ['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function storeResult(string $key, array $data): void
    {
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($data) {
            $item->expiresAfter(300);
            return $data;
        });
    }
}
