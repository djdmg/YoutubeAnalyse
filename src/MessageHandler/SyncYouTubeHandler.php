<?php

namespace App\MessageHandler;

use App\Message\SyncYouTubeMessage;
use App\Repository\UserRepository;
use App\Service\YouTubeSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class SyncYouTubeHandler
{
    public function __construct(
        private readonly UserRepository   $userRepo,
        private readonly YouTubeSyncService $syncService,
        private readonly CacheInterface   $cache,
    ) {}

    public function __invoke(SyncYouTubeMessage $message): void
    {
        $cacheKey = 'job_' . $message->jobId;

        $user = $this->userRepo->find($message->userId);
        if (!$user) {
            $this->storeResult($cacheKey, ['status' => 'error', 'message' => 'Utilisateur introuvable.']);
            return;
        }

        try {
            $result = $this->syncService->syncForUser($user);
            $this->storeResult($cacheKey, [
                'status'  => 'done',
                'message' => sprintf('Synchronisation réussie : %d vidéos synchronisées.', $result['videos_synced'] ?? 0),
                'result'  => $result,
            ]);
        } catch (\Throwable $e) {
            $this->storeResult($cacheKey, ['status' => 'error', 'message' => $e->getMessage()]);
            throw $e;
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
