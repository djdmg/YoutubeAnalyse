<?php

namespace App\Twig;

use App\Repository\UserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

class AdminExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly Security $security,
    ) {}

    public function getGlobals(): array
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return [];
        }

        return [
            'pending_approval_count' => $this->userRepository->count(['isApproved' => false]),
        ];
    }
}
