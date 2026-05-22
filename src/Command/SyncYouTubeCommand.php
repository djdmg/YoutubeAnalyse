<?php

namespace App\Command;

use App\Service\YouTubeDataService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'youtube:sync', description: 'Synchronise les métriques YouTube')]
class SyncYouTubeCommand extends Command
{
    public function __construct(private readonly YouTubeDataService $youtubeService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('YouTube Analytics Sync');

        try {
            $result = $this->youtubeService->syncAll();
            $io->success(sprintf(
                'Synchronisation réussie ! Chaîne: %s | Vidéos: %d | Abonnés: %s | Vues: %s',
                $result['channel'],
                $result['videos_synced'],
                number_format($result['subscribers'], 0, ',', ' '),
                number_format($result['views'], 0, ',', ' ')
            ));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Erreur: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
