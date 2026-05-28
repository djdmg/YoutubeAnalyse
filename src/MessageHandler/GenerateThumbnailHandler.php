<?php

namespace App\MessageHandler;

use App\Entity\AiReport;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Message\GenerateThumbnailMessage;
use App\Repository\VideoRepository;
use App\Service\GeminiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class GenerateThumbnailHandler
{
    public function __construct(
        private readonly GeminiService          $gemini,
        private readonly CacheInterface         $cache,
        private readonly EntityManagerInterface  $em,
        private readonly VideoRepository         $videoRepo,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function __invoke(GenerateThumbnailMessage $message): void
    {
        $cacheKey = 'thumbnail_job_' . $message->jobId;

        $report = new AiReport();
        $report->setType(AiReportType::ThumbnailGeneration);
        $report->setStatus(AiReportStatus::Pending);
        $report->setModelVersion($message->model);

        $video = $this->videoRepo->findByYoutubeId($message->videoId);
        if ($video) {
            $report->setVideo($video);
        }

        $startTime = microtime(true);

        try {
            $base64 = $this->gemini->generateImage($message->prompt, $message->model);

            if (!$base64) {
                $this->storeResult($cacheKey, ['status' => 'error', 'message' => 'Aucune image reçue du modèle.']);
                $report->setStatus(AiReportStatus::Failed);
                $report->setDurationMs((int)((microtime(true) - $startTime) * 1000));
                $this->em->persist($report);
                $this->em->flush();
                throw new \RuntimeException('Aucune image reçue du modèle.');
            }

            $dir = $this->projectDir . '/public/uploads/thumbnails/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filename = $message->videoId . '_gen_' . $message->jobId . '.png';
            file_put_contents($dir . $filename, base64_decode($base64));

            $report->setStatus(AiReportStatus::Done);
            $report->setDurationMs((int)((microtime(true) - $startTime) * 1000));
            $report->setTokensInput(1);
            $report->setTokensOutput(0);
            $report->setPayload(['filename' => $filename, 'prompt' => $message->prompt]);
            $this->em->persist($report);
            $this->em->flush();

            $this->storeResult($cacheKey, [
                'status'   => 'done',
                'filename' => $filename,
                'url'      => '/uploads/thumbnails/' . $filename . '?t=' . time(),
            ]);

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $msg = preg_replace('/([?&]key=)[^&\s"\']+/', '$1***', $msg);
            if (str_contains($msg, '429')) {
                $msg = 'Quota API Gemini dépassé (429). Attendez quelques secondes et réessayez.';
            }

            if ($report->getStatus() === AiReportStatus::Pending) {
                $report->setStatus(AiReportStatus::Failed);
                $report->setDurationMs((int)((microtime(true) - $startTime) * 1000));
                $this->em->persist($report);
                $this->em->flush();
            }

            $this->storeResult($cacheKey, ['status' => 'error', 'message' => $msg]);
            throw $e;
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
