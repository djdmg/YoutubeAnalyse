<?php

namespace App\Command;

use App\Repository\GoogleTokenRepository;
use App\Service\EmailNotificationService;
use App\Service\QuotaGuardService;
use App\Service\TelegramNotificationService;
use App\Service\YouTubeSyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:youtube:sync',
    description: 'Synchronise vidéos, métriques et commentaires depuis YouTube',
)]
class SyncAnalyticsCommand extends Command
{
    public function __construct(
        private readonly YouTubeSyncService          $syncService,
        private readonly GoogleTokenRepository       $tokenRepo,
        private readonly QuotaGuardService           $quotaGuard,
        private readonly EmailNotificationService    $emailService,
        private readonly TelegramNotificationService $telegramService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('YouTube Analytics Sync');

        $io->info(sprintf('Quota utilisé aujourd\'hui : %d / 9000 units', $this->quotaGuard->getUsed()));

        $tokens = $this->tokenRepo->findAllWithRefreshToken();
        if (empty($tokens)) {
            $io->warning('Aucun token OAuth trouvé. Connectez d\'abord un compte YouTube.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $user = $token->getUser();
            $io->section(sprintf('Synchronisation : %s (%s)', $user->getDisplayName(), $token->getChannelTitle() ?? $token->getChannelId()));

            try {
                if (!$this->quotaGuard->hasQuota(200)) {
                    $io->error('Quota journalier YouTube API presque épuisé. Sync annulée.');
                    return Command::FAILURE;
                }

                $result = $this->syncService->syncForUser($user);

                $io->success(sprintf(
                    'Sync OK : %d vidéos, %d métriques quotidiennes, %d nouveaux commentaires',
                    $result['videos_synced'],
                    $result['daily_metrics_synced'] ?? 0,
                    $result['comments_synced'],
                ));

                if ($result['impressions_ctr_updated'] > 0 || $result['demographics_updated'] > 0 || $result['traffic_sources_updated'] > 0) {
                    $io->writeln(sprintf(
                        '  Reporting API : CTR/impressions %d lignes, démographies %d lignes, sources trafic %d lignes',
                        $result['impressions_ctr_updated'],
                        $result['demographics_updated'],
                        $result['traffic_sources_updated'],
                    ));
                } else {
                    $io->writeln('  Reporting API : jobs créés (premiers rapports disponibles dans 24-48h)');
                }

                $channel = $token->getChannelTitle() ?? $token->getChannelId();

                $emailError = $this->emailService->sendSyncSummary($user, $result, $channel, $this->quotaGuard->getUsed());
                if ($emailError) {
                    $io->writeln('  <fg=yellow>Email non envoyé : ' . $emailError . '</>');
                } elseif ($this->emailService->isConfiguredForUser($user)) {
                    $io->writeln('  ✉ Résumé envoyé à ' . $user->getNotifEmail());
                }

                $this->telegramService->sendSyncSummary($user, $result, $channel);
                if ($user->hasTelegramConfigured()) {
                    $io->writeln('  ✈ Telegram sync summary envoyé');
                }

            } catch (\RuntimeException $e) {
                if (str_contains($e->getMessage(), 'quota')) {
                    $io->error('Quota YouTube API dépassé : ' . $e->getMessage());
                    return Command::FAILURE;
                }
                $io->error('Erreur sync : ' . $e->getMessage());
            } catch (\Exception $e) {
                $io->error('Erreur inattendue : ' . $e->getMessage());
            }
        }

        $io->info(sprintf('Quota final : %d / 9000 units', $this->quotaGuard->getUsed()));
        return Command::SUCCESS;
    }
}
