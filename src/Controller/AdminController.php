<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_users')]
    public function index(): Response
    {
        $pending  = $this->userRepository->findBy(['isApproved' => false], ['createdAt' => 'DESC']);
        $approved = $this->userRepository->findBy(['isApproved' => true],  ['lastLoginAt' => 'DESC']);

        return $this->render('admin/users.html.twig', [
            'pending_users'  => $pending,
            'approved_users' => $approved,
        ]);
    }

    #[Route('/approve/{id}', name: 'admin_approve', methods: ['POST'])]
    public function approve(User $user): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Impossible de modifier ton propre compte.');
            return $this->redirectToRoute('admin_users');
        }

        $user->setIsApproved(true)
             ->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->addFlash('success', "✓ {$user->getDisplayName()} approuvé.");
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/reject/{id}', name: 'admin_reject', methods: ['POST'])]
    public function reject(User $user): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Impossible de modifier ton propre compte.');
            return $this->redirectToRoute('admin_users');
        }

        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "Utilisateur supprimé.");
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/toggle-admin/{id}', name: 'admin_toggle_admin', methods: ['POST'])]
    public function toggleAdmin(User $user): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Impossible de modifier ton propre rôle.');
            return $this->redirectToRoute('admin_users');
        }

        if ($user->isAdmin()) {
            $user->setRoles(['ROLE_USER']);
            $this->addFlash('success', "{$user->getDisplayName()} n'est plus admin.");
        } else {
            $user->setRoles(['ROLE_ADMIN']);
            $this->addFlash('success', "{$user->getDisplayName()} est maintenant admin.");
        }

        $this->em->flush();
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/revoke/{id}', name: 'admin_revoke', methods: ['POST'])]
    public function revoke(User $user): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Impossible de modifier ton propre compte.');
            return $this->redirectToRoute('admin_users');
        }

        $user->setIsApproved(false)->setApprovedAt(null);
        $this->em->flush();

        $this->addFlash('success', "Accès de {$user->getDisplayName()} révoqué.");
        return $this->redirectToRoute('admin_users');
    }

    #[Route('/delete/{id}', name: 'admin_delete', methods: ['POST'])]
    public function delete(User $user): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Impossible de supprimer ton propre compte.');
            return $this->redirectToRoute('admin_users');
        }

        $name = $user->getDisplayName();
        $this->em->remove($user);
        $this->em->flush();

        $this->addFlash('success', "{$name} supprimé.");
        return $this->redirectToRoute('admin_users');
    }
}
