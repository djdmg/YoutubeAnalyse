<?php

namespace App\Command;

use App\Repository\DailyMetricRepository;
use App\Repository\GoogleTokenRepository;
use App\Service\EmailNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:report:daily',
    description: 'Envoie le rapport de vues quotidien par email à 23h50',
)]
class DailyReportCommand extends Command
{
    public function __construct(
        private readonly GoogleTokenRepository  $tokenRepo,
        private readonly DailyMetricRepository  $metricRepo,
        private readonly EmailNotificationService $emailService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Rapport quotidien YouTube');

        $tokens = $this->tokenRepo->findAllWithRefreshToken();
        if (empty($tokens)) {
            $io->warning('Aucun token OAuth trouvé.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $user = $token->getUser();
            $io->section(sprintf('%s (%s)', $user->getDisplayName(), $token->getChannelTitle() ?? $token->getChannelId()));

            if (!$user->hasSmtpConfigured()) {
                $io->writeln('  SMTP non configuré — email ignoré.');
                continue;
            }

            // Use the most recent date that has data (YouTube Analytics has 1-2 day lag)
            $date = $this->metricRepo->getLatestDateWithData($user);
            if (!$date) {
                $io->writeln('  Aucune donnée disponible.');
                continue;
            }

            $stats     = $this->metricRepo->getTotalsForDate($user, $date);
            $topVideos = $this->metricRepo->getTopVideosForDate($user, $date, 8);

            $io->writeln(sprintf('  Date des données : %s · %s vues · %d vidéos actives',
                $date->format('d/m/Y'),
                number_format((int) ($stats['views'] ?? 0), 0, ',', ' '),
                count($topVideos),
            ));

            $error = $this->emailService->sendDailyReport($user, $stats, $topVideos, $date);
            if ($error) {
                $io->error('Email non envoyé : ' . $error);
            } else {
                $io->writeln('  ✉ Rapport envoyé à ' . $user->getNotifEmail());
            }
        }

        return Command::SUCCESS;
    }
}
