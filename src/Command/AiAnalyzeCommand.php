<?php

namespace App\Command;

use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\GoogleTokenRepository;
use App\Service\AiAnalysisService;
use App\Service\EmailNotificationService;
use App\Service\TelegramNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ai:analyze',
    description: 'Exécute les analyses IA sur les vidéos YouTube',
)]
class AiAnalyzeCommand extends Command
{
    public function __construct(
        private readonly AiAnalysisService           $aiService,
        private readonly GoogleTokenRepository       $tokenRepo,
        private readonly AiReportRepository          $aiReportRepo,
        private readonly EmailNotificationService    $emailService,
        private readonly TelegramNotificationService $telegramService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', 't', InputOption::VALUE_OPTIONAL,
            'Type d\'analyse : title_optimization, comment_analysis, anomaly, prediction, upload_schedule'
        );
        $this->addOption('force', 'f', InputOption::VALUE_NONE,
            'Ignore les gardes de déclenchement (âge vidéo, 24h dedup, baseline anomalie, fenêtre 48h). Utile pour tester.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $typeArg = $input->getOption('type');
        $force   = (bool) $input->getOption('force');

        $type = null;
        if ($typeArg) {
            $type = AiReportType::tryFrom($typeArg);
            if (!$type) {
                $io->error("Type inconnu: {$typeArg}. Valeurs: " . implode(', ', array_column(AiReportType::cases(), 'value')));
                return Command::FAILURE;
            }
        }

        if ($force) {
            $io->note('Mode --force : gardes de déclenchement désactivées.');
        }

        $tokens = $this->tokenRepo->findAllWithRefreshToken();
        if (empty($tokens)) {
            $io->warning('Aucun token OAuth trouvé.');
            return Command::SUCCESS;
        }

        foreach ($tokens as $token) {
            $user = $token->getUser();
            $io->section(sprintf('Analyse IA : %s', $user->getDisplayName()));

            $this->aiService->setForce($force);

            try {
                $runStartedAt = new \DateTimeImmutable();

                if ($type) {
                    $result = $this->aiService->analyzeType($user, $type);
                } else {
                    $result = $this->aiService->analyzeAll($user);
                }

                $total = array_sum($result['counts']);
                $io->success(sprintf('%d rapport(s) IA généré(s)', $total));

                if ($total > 0) {
                    $newReports = $this->aiReportRepo->findGeneratedSince($user, $runStartedAt);

                    if ($user->hasSmtpConfigured()) {
                        $error = $this->emailService->sendAiRecommendations($user, $newReports);
                        if ($error) {
                            $io->warning('Email non envoyé : ' . $error);
                        } else {
                            $io->writeln(sprintf('  ✉ Email envoyé à %s (%d rapport(s))', $user->getNotifEmail(), count($newReports)));
                        }
                    }

                    $this->telegramService->sendAiRecommendations($user, $newReports);
                    if ($user->hasTelegramConfigured()) {
                        $io->writeln(sprintf('  ✈ Telegram envoyé (%d rapport(s))', count($newReports)));
                    }
                }

                foreach ($result['counts'] as $analysisType => $count) {
                    if ($count > 0) {
                        $io->writeln(sprintf('  ✓ %s : %d rapport(s)', $analysisType, $count));
                    }
                }

                if (!empty($result['skipped'])) {
                    $io->writeln('');
                    $io->writeln('<fg=yellow>Analyses ignorées (raisons) :</>');
                    foreach ($result['skipped'] as $reason) {
                        $io->writeln('  · ' . $reason);
                    }
                }

            } catch (\Exception $e) {
                $io->error('Erreur analyse IA : ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
