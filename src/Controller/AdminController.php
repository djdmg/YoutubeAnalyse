<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AppSettingRepository;
use App\Repository\UserRepository;
use App\Enum\AiReportType;
use App\Service\AiProviderFactory;
use App\Service\AiProviderInterface;
use App\Service\GeminiService;
use App\Service\TelegramNotificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly UserRepository         $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly AppSettingRepository   $settingRepo,
        private readonly AiProviderFactory      $aiFactory,
        private readonly GeminiService          $gemini,
        private readonly LoggerInterface        $logger,
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
        $taskModels = [];
        $aiTasks    = [];
        foreach (AiReportType::cases() as $type) {
            $taskModels[$type->value] = $this->settingRepo->get('ai_model_' . $type->value) ?? '';
            $aiTasks[]                = ['value' => $type->value, 'label' => $type->label()];
        }

        return $this->render('admin/settings.html.twig', [
            'telegram_token'   => $this->settingRepo->get(TelegramNotificationService::SETTING_KEY),
            'ai_provider'      => $this->settingRepo->get(AiProviderFactory::SETTING_PROVIDER) ?? AiProviderFactory::PROVIDER_CLAUDE,
            'gemini_api_key'   => $this->settingRepo->get(GeminiService::SETTING_API_KEY),
            'thumbnail_model'  => $this->settingRepo->get(GeminiService::SETTING_THUMBNAIL_MODEL) ?? 'imagen-3.0-generate-001',
            'image_models'     => $this->gemini->getAvailableModels(),
            'task_models'      => $taskModels,
            'ai_tasks_json'    => json_encode($aiTasks),
        ]);
    }

    #[Route('/settings/models', name: 'admin_settings_models')]
    public function modelsApi(Request $request): JsonResponse
    {
        try {
            $forceRefresh = (bool) $request->query->get('refresh');
            $provider = $this->aiFactory->activeProvider();
            $models   = $this->aiFactory->getAvailableModels($forceRefresh);

            // If we got nothing (even after fallback), retry once with a forced refresh
            if (empty($models)) {
                $models = $this->aiFactory->getAvailableModels(true);
            }

            $tiers = [
                ['id' => AiProviderInterface::TIER_FAST,     'name' => '⚡ Fast (défaut rapide)',     'tier' => 'fast'],
                ['id' => AiProviderInterface::TIER_BALANCED, 'name' => '⚖️ Balanced (défaut équilibré)', 'tier' => 'balanced'],
                ['id' => AiProviderInterface::TIER_FULL,     'name' => '🔬 Full (défaut complet)',    'tier' => 'full'],
            ];

            return new JsonResponse(['provider' => $provider, 'tiers' => $tiers, 'models' => $models]);
        } catch (\Throwable $e) {
            $this->logger->error('modelsApi failed: ' . $e->getMessage());
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
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
        } elseif ($section === 'models') {
            foreach (AiReportType::cases() as $type) {
                $model = trim((string) $request->request->get('ai_model_' . $type->value, ''));
                $this->settingRepo->set('ai_model_' . $type->value, $model ?: null);
            }
        } elseif ($section === 'ai') {
            $provider = $request->request->get('ai_provider', AiProviderFactory::PROVIDER_CLAUDE);
            if (!in_array($provider, [AiProviderFactory::PROVIDER_CLAUDE, AiProviderFactory::PROVIDER_GEMINI], true)) {
                $provider = AiProviderFactory::PROVIDER_CLAUDE;
            }

            $geminiKey = trim((string) $request->request->get('gemini_api_key', ''));
            $keyMessage = '';
            if ($geminiKey !== '') {
                if (!$this->gemini->validateApiKey($geminiKey)) {
                    if ($request->isXmlHttpRequest()) {
                        return new JsonResponse(['success' => false, 'message' => 'Clé API Gemini invalide ou inaccessible. Vérifiez votre clé sur Google AI Studio.']);
                    }
                    $this->addFlash('danger', 'Clé API Gemini invalide ou inaccessible.');
                    return $this->redirectToRoute('admin_settings');
                }
                $this->settingRepo->set(GeminiService::SETTING_API_KEY, $geminiKey);
                $this->gemini->clearModelsCache();
                $keyMessage = ' Clé Gemini validée ✓';
            }

            $this->settingRepo->set(AiProviderFactory::SETTING_PROVIDER, $provider);

            $thumbnailModel = trim((string) $request->request->get('thumbnail_model', ''));
            if ($thumbnailModel !== '') {
                $this->settingRepo->set(GeminiService::SETTING_THUMBNAIL_MODEL, $thumbnailModel);
            }

            $this->gemini->clearModelsCache();
        }

        $message = 'Paramètres sauvegardés.' . ($keyMessage ?? '');

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'success'       => true,
                'message'       => $message,
                'reload_models' => $section === 'ai',
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('admin_settings');
    }
}
