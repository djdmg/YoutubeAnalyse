<?php

namespace App\Command;

use App\Repository\DailyMetricRepository;
use App\Repository\GoogleTokenRepository;
use App\Service\EmailNotificationService;
use App\Service\TelegramNotificationService;
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
        private readonly GoogleTokenRepository      $tokenRepo,
        private readonly DailyMetricRepository      $metricRepo,
        private readonly EmailNotificationService    $emailService,
        private readonly TelegramNotificationService $telegramService,
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

            // Prefer yesterday's data; fall back to most recent available date (YouTube Analytics 1-2 day lag)
            $yesterday = new \DateTimeImmutable('yesterday midnight');
            $stats = $this->metricRepo->getTotalsForDate($user, $yesterday);
            if (!empty($stats) && ($stats['views'] ?? 0) > 0) {
                $date = $yesterday;
            } else {
                $date = $this->metricRepo->getLatestDateWithData($user);
            }

            if (!$date) {
                $io->writeln('  Aucune donnée disponible.');
                continue;
            }

            $stats     = $this->metricRepo->getTotalsForDate($user, $date);
            $topVideos = $this->metricRepo->getTopVideosForDate($user, $date, 8);
            $isDelayed = $date->format('Y-m-d') !== $yesterday->format('Y-m-d');

            $io->writeln(sprintf('  Date des données : %s%s · %s vues · %d vidéos actives',
                $date->format('d/m/Y'),
                $isDelayed ? ' ⚠ retard sync' : '',
                number_format((int) ($stats['views'] ?? 0), 0, ',', ' '),
                count($topVideos),
            ));

            if ($user->hasSmtpConfigured()) {
                $error = $this->emailService->sendDailyReport($user, $stats, $topVideos, $date, $isDelayed);
                if ($error) {
                    $io->error('Email non envoyé : ' . $error);
                } else {
                    $io->writeln('  ✉ Email envoyé à ' . $user->getNotifEmail());
                }
            }

            $this->telegramService->sendDailyReport($user, $stats, $topVideos, $date, $isDelayed);
            if ($user->hasTelegramConfigured()) {
                $io->writeln('  ✈ Telegram envoyé (chat ' . $user->getTelegramChatId() . ')');
            }
        }

        return Command::SUCCESS;
    }
}
