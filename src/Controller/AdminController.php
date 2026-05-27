<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AppSettingRepository;
use App\Repository\UserRepository;
use App\Service\AiProviderFactory;
use App\Service\GeminiService;
use App\Service\TelegramNotificationService;
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
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly AppSettingRepository   $settingRepo,
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

    private function validateCsrf(Request $request, User $user): bool
    {
        return $this->isCsrfTokenValid('admin_action_' . $user->getId(), $request->request->get('_token'));
    }

    #[Route('/approve/{id}', name: 'admin_approve', methods: ['POST'])]
    public function approve(User $user, Request $request): Response
    {
        if (!$this->validateCsrf($request, $user)) throw $this->createAccessDeniedException();
        if ($user === $this->getUser()) {
            return $this->ajaxOrFlash($request, false, 'Impossible de modifier ton propre compte.', 'admin_users');
        }

        $user->setIsApproved(true)->setApprovedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->ajaxOrFlash($request, true, "{$user->getDisplayName()} approuvé.", 'admin_users', [
            'action'    => 'approved',
            'is_admin'  => $user->isAdmin(),
            'role_label'=> $user->isAdmin() ? 'Admin' : 'Utilisateur',
        ]);
    }

    #[Route('/reject/{id}', name: 'admin_reject', methods: ['POST'])]
    public function reject(User $user, Request $request): Response
    {
        if (!$this->validateCsrf($request, $user)) throw $this->createAccessDeniedException();
        if ($user === $this->getUser()) {
            return $this->ajaxOrFlash($request, false, 'Impossible de modifier ton propre compte.', 'admin_users');
        }

        $this->em->remove($user);
        $this->em->flush();

        return $this->ajaxOrFlash($request, true, 'Utilisateur supprimé.', 'admin_users', ['action' => 'removed']);
    }

    #[Route('/toggle-admin/{id}', name: 'admin_toggle_admin', methods: ['POST'])]
    public function toggleAdmin(User $user, Request $request): Response
    {
        if (!$this->validateCsrf($request, $user)) throw $this->createAccessDeniedException();
        if ($user === $this->getUser()) {
            return $this->ajaxOrFlash($request, false, 'Impossible de modifier ton propre rôle.', 'admin_users');
        }

        if ($user->isAdmin()) {
            $user->setRoles(array_values(array_diff($user->getRoles(), ['ROLE_ADMIN'])));
            $msg = "{$user->getDisplayName()} n'est plus admin.";
        } else {
            $user->setRoles(array_unique(array_merge($user->getRoles(), ['ROLE_ADMIN'])));
            $msg = "{$user->getDisplayName()} est maintenant admin.";
        }
        $this->em->flush();

        return $this->ajaxOrFlash($request, true, $msg, 'admin_users', [
            'action'     => 'toggle_admin',
            'is_admin'   => $user->isAdmin(),
            'role_label' => $user->isAdmin() ? 'Admin' : 'Utilisateur',
        ]);
    }

    #[Route('/revoke/{id}', name: 'admin_revoke', methods: ['POST'])]
    public function revoke(User $user, Request $request): Response
    {
        if (!$this->validateCsrf($request, $user)) throw $this->createAccessDeniedException();
        if ($user === $this->getUser()) {
            return $this->ajaxOrFlash($request, false, 'Impossible de modifier ton propre compte.', 'admin_users');
        }

        $user->setIsApproved(false)->setApprovedAt(null);
        $this->em->flush();

        return $this->ajaxOrFlash($request, true, "Accès de {$user->getDisplayName()} révoqué.", 'admin_users', ['action' => 'removed']);
    }

    #[Route('/delete/{id}', name: 'admin_delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        if (!$this->validateCsrf($request, $user)) throw $this->createAccessDeniedException();
        if ($user === $this->getUser()) {
            return $this->ajaxOrFlash($request, false, 'Impossible de supprimer ton propre compte.', 'admin_users');
        }

        $name = $user->getDisplayName();
        $this->em->remove($user);
        $this->em->flush();

        return $this->ajaxOrFlash($request, true, "{$name} supprimé.", 'admin_users', ['action' => 'removed']);
    }

    private function ajaxOrFlash(Request $request, bool $success, string $message, string $route, array $extra = []): Response
    {
        if ($request->isXmlHttpRequest()) {
            $status = $success ? 200 : 400;
            return new JsonResponse(array_merge(['success' => $success, 'message' => $message], $extra), $status);
        }
        $this->addFlash($success ? 'success' : 'error', $message);
        return $this->redirectToRoute($route);
    }

    #[Route('/settings', name: 'admin_settings')]
    public function settings(): Response
    {
        return $this->render('admin/settings.html.twig', [
            'telegram_token' => $this->settingRepo->get(TelegramNotificationService::SETTING_KEY),
            'ai_provider'    => $this->settingRepo->get(AiProviderFactory::SETTING_PROVIDER) ?? AiProviderFactory::PROVIDER_CLAUDE,
            'gemini_api_key' => $this->settingRepo->get(GeminiService::SETTING_API_KEY),
        ]);
    }

    #[Route('/settings/save', name: 'admin_settings_save', methods: ['POST'])]
    public function settingsSave(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_settings', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $section = $request->request->get('section', 'telegram');

        if ($section === 'telegram') {
            $token = trim((string) $request->request->get('telegram_token', ''));
            $this->settingRepo->set(TelegramNotificationService::SETTING_KEY, $token ?: null);
        } elseif ($section === 'ai') {
            $provider = $request->request->get('ai_provider', AiProviderFactory::PROVIDER_CLAUDE);
            if (!in_array($provider, [AiProviderFactory::PROVIDER_CLAUDE, AiProviderFactory::PROVIDER_GEMINI], true)) {
                $provider = AiProviderFactory::PROVIDER_CLAUDE;
            }
            $this->settingRepo->set(AiProviderFactory::SETTING_PROVIDER, $provider);

            $geminiKey = trim((string) $request->request->get('gemini_api_key', ''));
            if ($geminiKey !== '') {
                $this->settingRepo->set(GeminiService::SETTING_API_KEY, $geminiKey);
            }
        }

        if ($request->isXmlHttpRequest()) {
            return new \Symfony\Component\HttpFoundation\JsonResponse(['success' => true, 'message' => 'Paramètres sauvegardés.']);
        }

        $this->addFlash('success', 'Paramètres sauvegardés.');
        return $this->redirectToRoute('admin_settings');
    }
}
