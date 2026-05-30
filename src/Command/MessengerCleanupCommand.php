<?php

namespace App\Command;

use App\Repository\MessengerLogRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:messenger:cleanup-stale',
    description: 'Supprime les messages Messenger en traitement depuis trop longtemps',
)]
class MessengerCleanupCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly MessengerLogRepository $logRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age', null, InputOption::VALUE_OPTIONAL, 'Age maximum en secondes pour un message en traitement', 3600)
            ->addOption('queue', null, InputOption::VALUE_OPTIONAL, 'Nom de queue Doctrine Messenger à nettoyer', 'default')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulation sans suppression');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $maxAge  = max(60, (int) $input->getOption('max-age'));
        $queue   = (string) $input->getOption('queue');
        $dryRun  = (bool) $input->getOption('dry-run');
        $before  = new \DateTimeImmutable("-{$maxAge} seconds");
        $reason  = sprintf('Message tué automatiquement après plus de %d secondes de traitement.', $maxAge);

        $io->title('Nettoyage des messages Messenger bloqués' . ($dryRun ? ' [DRY RUN]' : ''));
        $io->text(sprintf('Seuil : avant %s · queue : %s', $before->format('Y-m-d H:i:s'), $queue));

        $staleTransport = $this->countStaleTransportMessages($queue, $before);
        $staleLogs      = $this->logRepo->countStaleProcessing($before);

        if ($dryRun) {
            $io->table(['Source', 'Messages bloqués'], [
                ['messenger_messages', $staleTransport],
                ['messenger_log', $staleLogs],
            ]);
            return Command::SUCCESS;
        }

        $deletedTransport = $this->deleteStaleTransportMessages($queue, $before);
        $failedLogs       = $this->logRepo->failStaleProcessing($before, $reason);

        $io->success(sprintf('Messages transport supprimés : %d', $deletedTransport));
        $io->success(sprintf('Logs marqués failed : %d', $failedLogs));

        return Command::SUCCESS;
    }

    private function countStaleTransportMessages(string $queue, \DateTimeImmutable $before): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NOT NULL AND delivered_at < :before',
                ['queue' => $queue, 'before' => $before->format('Y-m-d H:i:s')]
            );
        } catch (TableNotFoundException) {
            return 0;
        }
    }

    private function deleteStaleTransportMessages(string $queue, \DateTimeImmutable $before): int
    {
        try {
            return (int) $this->connection->executeStatement(
                'DELETE FROM messenger_messages WHERE queue_name = :queue AND delivered_at IS NOT NULL AND delivered_at < :before',
                ['queue' => $queue, 'before' => $before->format('Y-m-d H:i:s')]
            );
        } catch (TableNotFoundException) {
            return 0;
        }
    }
}
