<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileEmailSettingsType;
use App\Service\EmailNotificationService;
use App\Service\TelegramNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/profile', name: 'profile_')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface      $em,
        private readonly EmailNotificationService    $emailService,
        private readonly TelegramNotificationService $telegramService,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ProfileEmailSettingsType::class, $user, [
            'has_password' => (bool) $user->getSmtpPassword(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Only update password if a new value was entered
            $newPassword = $form->get('smtpPassword')->getData();
            if ($newPassword !== null && $newPassword !== '') {
                $user->setSmtpPassword($newPassword);
            }

            $this->em->flush();
            $this->addFlash('success', 'Paramètres email sauvegardés.');
            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/index.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
        ]);
    }

    #[Route('/test-email', name: 'test_email', methods: ['POST'])]
    public function testEmail(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->hasSmtpConfigured()) {
            $this->addFlash('error', 'Configurez d\'abord vos paramètres SMTP.');
            return $this->redirectToRoute('profile_index');
        }

        $error = $this->emailService->sendTestEmail($user);
        if ($error) {
            $this->addFlash('error', 'Échec de l\'envoi : ' . $error);
        } else {
            $this->addFlash('success', 'Email de test envoyé à ' . $user->getNotifEmail());
        }

        return $this->redirectToRoute('profile_index');
    }

    #[Route('/test-telegram', name: 'test_telegram', methods: ['POST'])]
    public function testTelegram(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$user->hasTelegramConfigured()) {
            $this->addFlash('error', 'Configurez d\'abord votre Telegram Chat ID.');
            return $this->redirectToRoute('profile_index');
        }

        $this->telegramService->sendSyncSummary($user, [
            'videos_synced'       => 12,
            'comments_synced'     => 34,
            'search_terms_synced' => 56,
        ], 'Test — YouTube Analyse');

        $this->addFlash('success', 'Message Telegram de test envoyé au chat ' . $user->getTelegramChatId());
        return $this->redirectToRoute('profile_index');
    }
}
