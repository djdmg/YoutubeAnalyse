<?php

namespace App\Command;

use App\Repository\AiReportRepository;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:data:cleanup',
    description: 'Supprime les données obsolètes (rapports IA, métriques, commentaires)',
)]
class DataCleanupCommand extends Command
{
    public function __construct(
        private readonly AiReportRepository   $aiReportRepo,
        private readonly DailyMetricRepository $metricRepo,
        private readonly CommentRepository    $commentRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep-reports',  null, InputOption::VALUE_OPTIONAL, 'Rétention rapports IA (jours)',              90)
            ->addOption('keep-metrics',  null, InputOption::VALUE_OPTIONAL, 'Rétention métriques quotidiennes (jours)',   365)
            ->addOption('keep-comments', null, InputOption::VALUE_OPTIONAL, 'Rétention commentaires (jours)',             180)
            ->addOption('dry-run',       null, InputOption::VALUE_NONE,     'Simulation sans suppression');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $keepReports  = max(1, (int) $input->getOption('keep-reports'));
        $keepMetrics  = max(1, (int) $input->getOption('keep-metrics'));
        $keepComments = max(1, (int) $input->getOption('keep-comments'));

        $io->title('Nettoyage des données' . ($dryRun ? ' [DRY RUN — aucune suppression]' : ''));
        $io->table(
            ['Type', 'Seuil'],
            [
                ['Rapports IA',            ">{$keepReports}j"],
                ['Métriques quotidiennes', ">{$keepMetrics}j"],
                ['Commentaires',           ">{$keepComments}j"],
            ]
        );

        if ($dryRun) {
            $io->warning('Mode dry-run : relancez sans --dry-run pour appliquer.');
            return Command::SUCCESS;
        }

        $deletedReports  = $this->aiReportRepo->deleteOlderThan(new \DateTimeImmutable("-{$keepReports} days"));
        $deletedMetrics  = $this->metricRepo->deleteOlderThan(new \DateTimeImmutable("-{$keepMetrics} days"));
        $deletedComments = $this->commentRepo->deleteOlderThan(new \DateTimeImmutable("-{$keepComments} days"));

        $io->success("Rapports IA supprimés  : {$deletedReports}");
        $io->success("Métriques supprimées   : {$deletedMetrics}");
        $io->success("Commentaires supprimés : {$deletedComments}");

        return Command::SUCCESS;
    }
}
