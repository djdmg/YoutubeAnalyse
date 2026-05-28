<?php

namespace App\MessageHandler;

use App\Message\GenerateThumbnailMessage;
use App\Service\GeminiService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class GenerateThumbnailHandler
{
    public function __construct(
        private readonly GeminiService $gemini,
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function __invoke(GenerateThumbnailMessage $message): void
    {
        $cacheKey = 'thumbnail_job_' . $message->jobId;

        try {
            $base64 = $this->gemini->generateImage($message->prompt, $message->model);

            if (!$base64) {
                $this->storeResult($cacheKey, ['status' => 'error', 'message' => 'Aucune image reçue du modèle.']);
                throw new \RuntimeException('Aucune image reçue du modèle.');
            }

            $dir = $this->projectDir . '/public/uploads/thumbnails/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $previewFile = $message->videoId . '_preview.png';
            file_put_contents($dir . $previewFile, base64_decode($base64));

            $this->storeResult($cacheKey, [
                'status' => 'done',
                'url'    => '/uploads/thumbnails/' . $previewFile . '?t=' . time(),
            ]);

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $msg = preg_replace('/([?&]key=)[^&\s"\']+/', '$1***', $msg);
            if (str_contains($msg, '429')) {
                $msg = 'Quota API Gemini dépassé (429). Attendez quelques secondes et réessayez.';
            }
            $this->storeResult($cacheKey, ['status' => 'error', 'message' => $msg]);
            throw $e; // re-throw so Messenger marks the message as failed
        }
    }

    private function storeResult(string $key, array $data): void
    {
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($data) {
            $item->expiresAfter(600);
            return $data;
        });
    }
}
