<?php

namespace App\Controller;

use App\Entity\Goal;
use App\Entity\User;
use App\Repository\GoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/goals')]
class GoalController extends AbstractController
{
    public function __construct(
        private readonly GoalRepository $goalRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'goal_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $data = json_decode($request->getContent(), true);
        if (!$data) {
            // Try form-encoded
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
            } catch (\Exception) {
                // ignore bad date
            }
        }

        $this->em->persist($goal);
        $this->em->flush();

        return new JsonResponse([
            'id'            => $goal->getId(),
            'label'         => $goal->getLabel(),
            'type'          => $goal->getType(),
            'targetValue'   => $goal->getTargetValue(),
            'currentValue'  => $goal->getCurrentValue(),
            'progressPercent' => $goal->getProgressPercent(),
        ], Response::HTTP_CREATED);
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
}
