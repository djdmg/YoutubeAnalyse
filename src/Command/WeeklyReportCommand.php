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
    name: 'app:report:weekly',
    description: 'Envoie le bilan hebdomadaire (lundi matin) par email et Telegram',
)]
class WeeklyReportCommand extends Command
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
        $io->title('Bilan hebdomadaire YouTube');

        // Last Monday 00:00 → last Sunday 23:59
        $weekEnd   = new \DateTimeImmutable('last sunday 23:59:59');
        $weekStart = new \DateTimeImmutable('last monday 00:00:00');

        // If today is Monday, "last monday" resolves to today — go back 7 days
        if ($weekStart->format('Y-m-d') === (new \DateTimeImmutable())->format('Y-m-d')) {
            $weekStart = $weekStart->modify('-7 days');
            $weekEnd   = $weekEnd->modify('-7 days');
        }

        $io->text(sprintf('Période : %s → %s', $weekStart->format('d/m/Y'), $weekEnd->format('d/m/Y')));

        $tokens = $this->tokenRepo->findAllWithRefreshToken();
        if (empty($tokens)) {
            $io->warning('Aucun token OAuth trouvé.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $user = $token->getUser();
            $io->section(sprintf('%s (%s)', $user->getDisplayName(), $token->getChannelTitle() ?? $token->getChannelId()));

            $stats     = $this->metricRepo->getTotalsForRange($user, $weekStart, $weekEnd);
            $topVideos = $this->metricRepo->getTopVideosForRange($user, $weekStart, $weekEnd, 5);
            $views     = (int) ($stats['views'] ?? 0);

            $io->writeln(sprintf('  %s vues · %d vidéos actives', number_format($views, 0, ',', ' '), count($topVideos)));

            if ($user->hasSmtpConfigured()) {
                $error = $this->emailService->sendWeeklyReport($user, $stats, $topVideos, $weekStart, $weekEnd);
                if ($error) {
                    $io->error('Email non envoyé : ' . $error);
                } else {
                    $io->writeln('  ✉ Email envoyé à ' . $user->getNotifEmail());
                }
            } else {
                $io->writeln('  SMTP non configuré — email ignoré.');
            }

            $this->telegramService->sendWeeklyReport($user, $stats, $topVideos, $weekStart, $weekEnd);
            if ($user->hasTelegramConfigured()) {
                $io->writeln('  ✈ Telegram envoyé (chat ' . $user->getTelegramChatId() . ')');
            }
        }

        return Command::SUCCESS;
    }
}
