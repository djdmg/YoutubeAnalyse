<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ChannelController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/channel/switch', name: 'channel_switch', methods: ['POST'])]
    public function switch(Request $request): Response
    {
        /** @var User $user */
        $user      = $this->getUser();
        $channelId = $request->request->get('channelId');

        // Validate that the channelId belongs to this user
        $validIds = $user->getGoogleTokens()->map(fn($t) => $t->getChannelId())->toArray();

        if ($channelId && in_array($channelId, $validIds, true)) {
            $user->setActiveChannelId($channelId);
            $this->em->flush();
            $this->addFlash('success', 'Chaîne active mise à jour.');
        } else {
            $this->addFlash('error', 'Chaîne invalide.');
        }

        return $this->redirectToRoute('dashboard');
    }
}
