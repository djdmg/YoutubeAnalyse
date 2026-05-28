<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\User;
use App\Message\GenerateGoalSuggestionsMessage;
use App\Repository\ChannelStatsRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\GoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;

#[IsGranted('ROLE_USER')]
#[Route('/goals')]
class GoalController extends AbstractController
{
    public function __construct(
        private readonly GoalRepository         $goalRepo,
        private readonly EntityManagerInterface  $em,
        private readonly ChannelStatsRepository  $channelStatsRepo,
        private readonly DailyMetricRepository   $dailyMetricRepo,
        private readonly MessageBusInterface     $bus,
        private readonly CacheInterface          $cache,
    ) {}

    #[Route('', name: 'goal_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user         = $this->getUser();
        $allGoals     = $this->goalRepo->findAllForUser($user);
        $activeGoals  = array_values(array_filter($allGoals, fn($g) => !$g->isAchieved()));
        $doneGoals    = array_values(array_filter($allGoals, fn($g) => $g->isAchieved()));

        $this->syncCurrentValues($user, array_merge($activeGoals, $doneGoals));

        return $this->render('goals/index.html.twig', [
            'active_goals'   => $activeGoals,
            'achieved_goals' => $doneGoals,
        ]);
    }

    #[Route('', name: 'goal_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            $data = $request->request->all();
        }

        $type        = $data['type'] ?? null;
        $targetValue = (int) ($data['targetValue'] ?? 0);
        $label       = trim($data['label'] ?? '');
        $deadline    = $data['deadline'] ?? null;

        if (!in_array($type, ['subscribers', 'views', 'watch_time'], true)) {
            return new JsonResponse(['error' => 'Type invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if ($targetValue <= 0) {
            return new JsonResponse(['error' => 'La valeur cible doit être positive.'], Response::HTTP_BAD_REQUEST);
        }
        if ($label === '') {
            return new JsonResponse(['error' => 'Le nom est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $goal = new Goal();
        $goal->setUser($user)
             ->setType($type)
             ->setTargetValue($targetValue)
             ->setLabel($label);

        if ($deadline) {
            try {
                $goal->setDeadline(new \DateTimeImmutable($deadline));
            } catch (\Exception) {}
        }

        $this->em->persist($goal);
        $this->syncCurrentValues($user, [$goal]);
        $this->em->flush();

        return new JsonResponse([
            'id'              => $goal->getId(),
            'label'           => $goal->getLabel(),
            'type'            => $goal->getType(),
            'targetValue'     => $goal->getTargetValue(),
            'currentValue'    => $goal->getCurrentValue(),
            'progressPercent' => $goal->getProgressPercent(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/generate', name: 'goal_generate', methods: ['POST'])]
    public function generate(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $latestStats = $this->channelStatsRepo->findLatestForUser($user);
        $stats30     = $this->dailyMetricRepo->getGlobalStatsForUser($user, 30);
        $stats7      = $this->dailyMetricRepo->getGlobalStatsForUser($user, 7);
        $activeGoals = $this->goalRepo->findActiveForUser($user);

        $jobId = bin2hex(random_bytes(8));

        // Pre-seed cache as pending so the status endpoint can answer immediately
        $cacheKey = 'goal_suggestions_' . $jobId;
        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function ($item) {
            $item->expiresAfter(600);
            return ['status' => 'pending'];
        });

        $this->bus->dispatch(new GenerateGoalSuggestionsMessage(
            jobId:         $jobId,
            userId:        $user->getId(),
            subscribers:   $latestStats?->getSubscriberCount() ?? 0,
            views30:       (int)($stats30['total_views'] ?? 0),
            views7:        (int)($stats7['total_views'] ?? 0),
            watchTime30:   (int)($stats30['total_watch_time'] ?? 0),
            avgCtr:        round((float)($stats30['avg_ctr'] ?? 0), 2),
            existingGoals: implode(', ', array_map(fn($g) => '"' . $g->getLabel() . '"', $activeGoals)),
            today:         (new \DateTimeImmutable())->format('Y-m-d'),
        ));

        return new JsonResponse(['jobId' => $jobId]);
    }

    #[Route('/generate-status/{jobId}', name: 'goal_generate_status', methods: ['GET'])]
    public function generateStatus(string $jobId): JsonResponse
    {
        $cacheKey = 'goal_suggestions_' . $jobId;
        $result   = $this->cache->get($cacheKey, function ($item) {
            $item->expiresAfter(600);
            return ['status' => 'pending'];
        });

        return new JsonResponse($result);
    }

    #[Route('/{id}', name: 'goal_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $goal = $this->goalRepo->find($id);

        if (!$goal || $goal->getUser() !== $user) {
            return new JsonResponse(['error' => 'Objectif introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($goal);
        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }

    private function syncCurrentValues(User $user, array $goals): void
    {
        if (empty($goals)) return;

        $latestStats   = $this->channelStatsRepo->findLatestForUser($user);
        $globalStats   = $this->dailyMetricRepo->getGlobalStatsForUser($user, 30);
        $subscribers   = $latestStats?->getSubscriberCount() ?? 0;
        $views30       = (int) ($globalStats['total_views'] ?? 0);
        $watchTime30   = (int) ($globalStats['total_watch_time'] ?? 0);

        foreach ($goals as $goal) {
            $current = match ($goal->getType()) {
                'subscribers' => $subscribers,
                'views'       => $views30,
                'watch_time'  => $watchTime30,
                default       => $goal->getCurrentValue(),
            };
            $goal->setCurrentValue($current);

            if (!$goal->isAchieved() && $current >= $goal->getTargetValue()) {
                $goal->setIsAchieved(true);
                $goal->setAchievedAt(new \DateTimeImmutable());
            }
        }
        $this->em->flush();
    }
}
