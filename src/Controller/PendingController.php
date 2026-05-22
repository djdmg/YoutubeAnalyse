<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PendingController extends AbstractController
{
    #[Route('/pending-approval', name: 'pending_approval')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isApproved()) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('auth/pending.html.twig');
    }
}
