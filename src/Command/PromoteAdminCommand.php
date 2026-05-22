<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:promote-admin', description: 'Promouvoir un utilisateur en admin')]
class PromoteAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Email de l\'utilisateur');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        if (!$email) {
            $users = $this->userRepository->findAll();
            if (empty($users)) {
                $io->error('Aucun utilisateur en base. Connecte-toi d\'abord via Google.');
                return Command::FAILURE;
            }
            $choices = array_map(fn($u) => "{$u->getEmail()} — {$u->getDisplayName()}", $users);
            $choice = $io->choice('Quel utilisateur promouvoir ?', $choices);
            $index = array_search($choice, $choices);
            $user = $users[$index];
        } else {
            $user = $this->userRepository->findByEmail($email);
            if (!$user) {
                $io->error("Utilisateur '{$email}' introuvable.");
                return Command::FAILURE;
            }
        }

        $user->setRoles(['ROLE_ADMIN'])
             ->setIsApproved(true)
             ->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();

        $io->success("{$user->getDisplayName()} ({$user->getEmail()}) est maintenant admin et approuvé.");
        return Command::SUCCESS;
    }
}
