<?php

namespace App\Command;

use App\Repository\VideoRepository;
use App\Service\GeminiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsCommand(name: 'app:thumbnail:generate', description: 'Generate a thumbnail image in background')]
class GenerateThumbnailCommand extends Command
{
    public function __construct(
        private readonly GeminiService  $gemini,
        private readonly VideoRepository $videoRepo,
        private readonly CacheInterface $cache,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('jobId',   InputArgument::REQUIRED)
            ->addArgument('videoId', InputArgument::REQUIRED)
            ->addArgument('model',   InputArgument::REQUIRED)
            ->addArgument('prompt',  InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId   = $input->getArgument('jobId');
        $videoId = $input->getArgument('videoId');
        $model   = $input->getArgument('model');
        $prompt  = $input->getArgument('prompt');
        $cacheKey = 'thumbnail_job_' . $jobId;

        try {
            $base64 = $this->gemini->generateImage($prompt, $model);

            if (!$base64) {
                $this->storeResult($cacheKey, ['status' => 'error', 'message' => 'Aucune image reçue du modèle.']);
                return Command::FAILURE;
            }

            $dir = $this->projectDir . '/public/uploads/thumbnails/';
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $previewFile = $videoId . '_preview.png';
            file_put_contents($dir . $previewFile, base64_decode($base64));

            $this->storeResult($cacheKey, [
                'status' => 'done',
                'url'    => '/uploads/thumbnails/' . $previewFile . '?t=' . time(),
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $msg = preg_replace('/([?&]key=)[^&\s"\']+/', '$1***', $msg);
            if (str_contains($msg, '429')) {
                $msg = 'Quota API Gemini dépassé (429). Attendez quelques secondes et réessayez.';
            }
            $this->storeResult($cacheKey, ['status' => 'error', 'message' => $msg]);
            return Command::FAILURE;
        }
    }

    private function storeResult(string $key, array $data): void
    {
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($data) {
            $item->expiresAfter(600); // keep result 10 min
            return $data;
        });
    }
}
