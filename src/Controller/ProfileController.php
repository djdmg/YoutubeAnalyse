<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileEmailSettingsType;
use App\Service\EmailNotificationService;
use App\Service\TelegramNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        $form = $this->createForm(ProfileEmailSettingsType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Paramètres de notification sauvegardés.');
            return $this->redirectToRoute('profile_index');
        }

        return $this->render('profile/index.html.twig', [
            'form' => $form->createView(),
            'user' => $user,
            'email_configured' => $this->emailService->isConfiguredForUser($user),
            'smtp_global_configured' => $this->emailService->isConfigured(),
        ]);
    }

    #[Route('/test-email', name: 'test_email', methods: ['POST'])]
    public function testEmail(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->emailService->isConfiguredForUser($user)) {
            return $this->testResponse($request, false, 'Renseignez votre email et configurez le SMTP global côté admin.');
        }

        $error = $this->emailService->sendTestEmail($user);
        if ($error) {
            return $this->testResponse($request, false, 'Échec de l\'envoi : ' . $error);
        }
        return $this->testResponse($request, true, 'Email de test envoyé à ' . $user->getNotifEmail());
    }

    #[Route('/test-telegram', name: 'test_telegram', methods: ['POST'])]
    public function testTelegram(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $chatId = trim((string) $request->request->get('telegram_chat_id', ''));
        if ($chatId !== '') {
            $user->setTelegramChatId($chatId);
            $this->em->flush();
        }

        if (!$user->hasTelegramConfigured()) {
            return $this->testResponse($request, false, 'Renseignez un Chat ID avant de tester.');
        }

        $error = $this->telegramService->sendTest($user);
        if ($error) {
            return $this->testResponse($request, false, 'Telegram : ' . $error);
        }
        return $this->testResponse($request, true, 'Message Telegram envoyé au chat ' . $user->getTelegramChatId() . ' ✓');
    }

    private function testResponse(Request $request, bool $success, string $message): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => $success, 'message' => $message], $success ? 200 : 400);
        }
        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute('profile_index');
    }
}
