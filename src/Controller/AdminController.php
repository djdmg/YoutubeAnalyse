<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\AppSettingRepository;
use App\Repository\MessengerLogRepository;
use App\Repository\UserRepository;
use App\Enum\AiReportType;
use App\Service\AiProviderFactory;
use App\Service\AiProviderInterface;
use App\Service\EmailNotificationService;
use App\Service\GeminiService;
use App\Service\TelegramNotificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
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
        private readonly EmailNotificationService $emailService,
        private readonly GeminiService          $gemini,
        private readonly LoggerInterface        $logger,
        private readonly MessengerLogRepository $messengerLogRepo,
        private readonly KernelInterface        $kernel,
    ) {}

    private function logFilePath(): string
    {
        $dir = $this->kernel->getLogDir();
        $env = $this->kernel->getEnvironment();
        // Try env-specific file first, then prod.log as fallback
        foreach ([$env . '.log', 'prod.log', 'dev.log'] as $name) {
            $path = $dir . '/' . $name;
            if (file_exists($path)) {
                return $path;
            }
        }
        return $dir . '/' . $env . '.log';
    }

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
        $provider   = $this->settingRepo->get(AiProviderFactory::SETTING_PROVIDER) ?? AiProviderFactory::PROVIDER_CLAUDE;
        foreach (AiReportType::cases() as $type) {
            $taskModels[$type->value] = $this->settingRepo->get(sprintf('ai_model_%s_%s', $provider, $type->value)) ?? '';
            $aiTasks[]                = ['value' => $type->value, 'label' => $type->label()];
        }

        return $this->render('admin/settings.html.twig', [
            'telegram_token'   => $this->settingRepo->get(TelegramNotificationService::SETTING_KEY),
            'smtp_configured'  => $this->emailService->isConfigured(),
            'smtp_host'        => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_HOST),
            'smtp_port'        => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_PORT) ?? '587',
            'smtp_encryption'  => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_ENCRYPTION) ?? 'starttls',
            'smtp_user'        => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_USER),
            'smtp_password'    => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_PASSWORD),
            'smtp_from_email'  => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_FROM_EMAIL),
            'smtp_from_name'   => $this->settingRepo->get(EmailNotificationService::SETTING_SMTP_FROM_NAME) ?? 'YouTube Analyse',
            'ai_provider'      => $provider,
            'gemini_api_key'   => $this->settingRepo->get(GeminiService::SETTING_API_KEY),
            'thumbnail_model'        => $this->settingRepo->get(GeminiService::SETTING_THUMBNAIL_MODEL) ?? 'imagen-3.0-generate-001',
            'thumbnail_prompt_model' => $this->settingRepo->get(GeminiService::SETTING_PROMPT_MODEL) ?? 'balanced',
            'goals_model'            => $this->settingRepo->get(GeminiService::SETTING_GOALS_MODEL) ?? 'fast',
            'gemini_tier_fast'       => $this->settingRepo->get(GeminiService::SETTING_TIER_FAST) ?? '',
            'gemini_tier_balanced'   => $this->settingRepo->get(GeminiService::SETTING_TIER_BALANCED) ?? '',
            'gemini_tier_full'       => $this->settingRepo->get(GeminiService::SETTING_TIER_FULL) ?? '',
            'image_models'           => $this->gemini->getAvailableModels(),
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
        } elseif ($section === 'smtp') {
            $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_HOST, trim((string) $request->request->get('smtp_host', '')) ?: null);
            $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_PORT, trim((string) $request->request->get('smtp_port', '')) ?: null);
            $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_ENCRYPTION, trim((string) $request->request->get('smtp_encryption', '')) ?: 'starttls');
            $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_USER, trim((string) $request->request->get('smtp_user', '')) ?: null);
            $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_FROM_EMAIL, trim((string) $request->request->get('smtp_from_email', '')) ?: null);
            $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_FROM_NAME, trim((string) $request->request->get('smtp_from_name', '')) ?: 'YouTube Analyse');

            $smtpPassword = (string) $request->request->get('smtp_password', '');
            if ($smtpPassword !== '') {
                $this->settingRepo->set(EmailNotificationService::SETTING_SMTP_PASSWORD, $smtpPassword);
            }
        } elseif ($section === 'models') {
            $provider = $this->settingRepo->get(AiProviderFactory::SETTING_PROVIDER) ?? AiProviderFactory::PROVIDER_CLAUDE;
            foreach (AiReportType::cases() as $type) {
                $model = trim((string) $request->request->get('ai_model_' . $type->value, ''));
                $this->settingRepo->set(sprintf('ai_model_%s_%s', $provider, $type->value), $model ?: null);
            }
            // Special-task model settings (thumbnail, goals) live in this section
            $thumbnailModel = trim((string) $request->request->get('thumbnail_model', ''));
            if ($thumbnailModel !== '') {
                $this->settingRepo->set(GeminiService::SETTING_THUMBNAIL_MODEL, $thumbnailModel);
            }
            $promptModel = trim((string) $request->request->get('thumbnail_prompt_model', ''));
            if ($promptModel !== '') {
                $this->settingRepo->set(GeminiService::SETTING_PROMPT_MODEL, $promptModel);
            }
            $goalsModel = trim((string) $request->request->get('goals_model', ''));
            if ($goalsModel !== '') {
                $this->settingRepo->set(GeminiService::SETTING_GOALS_MODEL, $goalsModel);
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

            foreach ([
                'gemini_tier_fast'     => GeminiService::SETTING_TIER_FAST,
                'gemini_tier_balanced' => GeminiService::SETTING_TIER_BALANCED,
                'gemini_tier_full'     => GeminiService::SETTING_TIER_FULL,
            ] as $field => $setting) {
                $value = trim((string) $request->request->get($field, ''));
                $this->settingRepo->set($setting, $value ?: null);
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

    #[Route('/messenger', name: 'admin_messenger')]
    public function messenger(): Response
    {
        $logs   = $this->messengerLogRepo->findRecent(200);
        $counts = $this->messengerLogRepo->countByStatus();

        return $this->render('admin/messenger.html.twig', [
            'logs'   => $logs,
            'counts' => $counts,
        ]);
    }

    #[Route('/messenger/data', name: 'admin_messenger_data')]
    public function messengerData(): JsonResponse
    {
        $logs   = $this->messengerLogRepo->findRecent(200);
        $counts = $this->messengerLogRepo->countByStatus();

        $serialized = array_map(function ($log) {
            $payload = $log->getPayload();
            $preview = [];
            $i = 0;
            foreach ($payload as $k => $v) {
                if ($i++ >= 2) break;
                $preview[] = ['k' => $k, 'v' => mb_substr((string)$v, 0, 20)];
            }
            return [
                'id'          => $log->getId(),
                'messageClass'=> $log->getMessageClass(),
                'shortName'   => substr(strrchr($log->getMessageClass(), '\\') ?: $log->getMessageClass(), 1),
                'payload'     => $preview,
                'status'      => $log->getStatus(),
                'retryCount'  => $log->getRetryCount(),
                'durationMs'  => $log->getDurationMs(),
                'createdAt'   => $log->getCreatedAt()->format('d/m H:i:s'),
                'finishedAt'  => $log->getFinishedAt()?->format('d/m H:i:s'),
                'error'       => $log->getError(),
            ];
        }, $logs);

        return new JsonResponse(['counts' => $counts, 'logs' => $serialized]);
    }

    #[Route('/messenger/clear', name: 'admin_messenger_clear', methods: ['POST'])]
    public function messengerClear(): JsonResponse
    {
        $deleted = $this->messengerLogRepo->deleteAll();
        return new JsonResponse(['success' => true, 'deleted' => $deleted]);
    }

    #[Route('/messenger/{id}/retry', name: 'admin_messenger_retry', methods: ['POST'])]
    public function messengerRetry(int $id): Response
    {
        $this->addFlash('info', 'Pour rejouer les messages échoués : php bin/console messenger:failed:retry');
        return $this->redirectToRoute('admin_messenger');
    }

    #[Route('/logs', name: 'admin_logs')]
    public function logs(Request $request): Response
    {
        $logFile = $this->logFilePath();
        $lines   = [];
        $size    = 0;
        $exists  = file_exists($logFile);

        if ($exists) {
            $size  = filesize($logFile);
            $limit = (int) $request->query->get('lines', 500);
            $all   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $lines = array_slice($all, -$limit);
        }

        return $this->render('admin/logs.html.twig', [
            'lines'   => $lines,
            'size'    => $size,
            'exists'  => $exists,
            'logFile' => basename((string) $logFile),
        ]);
    }

    #[Route('/logs/data', name: 'admin_logs_data')]
    public function logsData(Request $request): JsonResponse
    {
        $logFile = $this->logFilePath();
        if (!file_exists($logFile)) {
            return new JsonResponse(['lines' => [], 'size' => 0]);
        }
        $limit = (int) $request->query->get('lines', 500);
        $all   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice($all, -$limit);
        return new JsonResponse(['lines' => $lines, 'size' => filesize($logFile)]);
    }

    #[Route('/logs/clear', name: 'admin_logs_clear', methods: ['POST'])]
    public function logsClear(): JsonResponse
    {
        $logFile = $this->logFilePath();
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }
        return new JsonResponse(['success' => true]);
    }
}
