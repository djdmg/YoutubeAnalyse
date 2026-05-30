<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AiReportType;
use App\Message\GenerateEditorialPlanMessage;
use App\Repository\AiReportRepository;
use App\Repository\AppSettingRepository;
use App\Service\GeminiService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[IsGranted('ROLE_USER')]
#[Route('/analytics/editorial')]
class EditorialController extends AbstractController
{
    public function __construct(
        private readonly AiReportRepository  $aiReportRepo,
        private readonly MessageBusInterface $bus,
        private readonly CacheInterface      $cache,
        private readonly AppSettingRepository $settingRepo,
    ) {}

    #[Route('', name: 'editorial_planning')]
    public function index(): Response
    {
        /** @var User $user */
        $user         = $this->getUser();
        $latestReport = $this->aiReportRepo->findLatestForUserByType($user, AiReportType::EditorialPlanning);
        $ideas        = [];

        if ($latestReport && $latestReport->getContent()) {
            $decoded = json_decode($latestReport->getContent(), true);
            if (is_array($decoded)) $ideas = $decoded;
        }

        return $this->render('analytics/editorial.html.twig', [
            'ideas'         => $ideas,
            'latest_report' => $latestReport,
        ]);
    }

    #[Route('/generate', name: 'editorial_generate', methods: ['POST'])]
    public function generate(): JsonResponse
    {
        /** @var User $user */
        $user     = $this->getUser();
        $jobId    = bin2hex(random_bytes(8));
        $cacheKey = 'editorial_plan_' . $jobId;

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) {
            $item->expiresAfter(1800);
            return ['status' => 'pending'];
        });

        $model = $this->settingRepo->get(GeminiService::SETTING_GOALS_MODEL) ?? 'balanced';

        $this->bus->dispatch(new GenerateEditorialPlanMessage(
            jobId:  $jobId,
            userId: $user->getId(),
            model:  $model,
        ));

        return new JsonResponse(['jobId' => $jobId]);
    }

    #[Route('/status/{jobId}', name: 'editorial_status', methods: ['GET'])]
    public function status(string $jobId): JsonResponse
    {
        $result = $this->cache->get('editorial_plan_' . $jobId, function (ItemInterface $item) {
            $item->expiresAfter(1800);
            return ['status' => 'pending'];
        });
        return new JsonResponse($result);
    }
}
