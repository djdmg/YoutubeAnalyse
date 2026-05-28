<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\GoogleTokenRepository;
use App\Service\GoogleAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

#[Route('/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly GoogleTokenRepository $tokenRepo,
    ) {}

    #[Route('/google', name: 'auth_google')]
    public function connect(Request $request): Response
    {
        $forceConsent = (bool) $request->query->get('force_consent', false);
        return $this->redirect($this->authService->getAuthUrl($forceConsent));
    }

    // The callback is handled by GoogleAuthenticator (security system)
    // This route only exists as a target — the authenticator intercepts it first.
    #[Route('/google/callback', name: 'auth_google_callback')]
    public function callback(): Response
    {
        return $this->redirectToRoute('dashboard');
    }

    #[Route('/google/select-channel', name: 'auth_select_channel_page')]
    #[IsGranted('ROLE_USER')]
    public function selectChannelPage(Request $request): Response
    {
        $channels = $request->getSession()->get('_pending_channels', []);

        if (empty($channels)) {
            return $this->redirectToRoute('dashboard');
        }

        return $this->render('auth/select_channel.html.twig', [
            'channels' => $channels,
        ]);
    }

    #[Route('/google/select-channel', name: 'auth_select_channel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function selectChannel(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $channelId = $request->request->get('channel_id');
        $channelTitle = $request->request->get('channel_title', '');

        if (!$channelId) {
            $this->addFlash('error', 'Aucune chaîne sélectionnée.');
            return $this->redirectToRoute('auth_select_channel_page');
        }

        try {
            $tokenData = $request->getSession()->get('_pending_token_data', []);
            $firewallName = 'main';

            // Brand Account scenario: channel already belongs to another Symfony user
            $existingToken = $this->tokenRepo->findByChannelId($channelId);
            if ($existingToken && $existingToken->getUser()->getId() !== $user->getId()) {
                $targetUser = $existingToken->getUser();
                $this->authService->saveTokenForUser($targetUser, $tokenData);
                $this->authService->selectChannelForUser($targetUser, $channelId, $channelTitle);

                $switchToken = new PostAuthenticationToken($targetUser, $firewallName, $targetUser->getRoles());
                $request->getSession()->set('_security_' . $firewallName, serialize($switchToken));
                $request->getSession()->remove('_pending_channels');
                $request->getSession()->remove('_pending_token_data');
                $request->getSession()->remove('_google_user');
                $this->addFlash('success', 'Chaîne connectée avec succès !');
                return $this->redirectToRoute('dashboard');
            }

            $this->authService->saveTokenForUser($user, $tokenData);
            $this->authService->selectChannelForUser($user, $channelId, $channelTitle);
            $request->getSession()->remove('_pending_channels');
            $request->getSession()->remove('_pending_token_data');
            $request->getSession()->remove('_google_user');
            $this->addFlash('success', 'Chaîne connectée avec succès !');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dashboard');
    }

    #[Route('/google/revoke', name: 'auth_google_revoke', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function revoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->authService->revokeTokenForUser($user);
        $this->addFlash('success', 'Chaîne YouTube déconnectée.');
        return $this->redirectToRoute('dashboard');
    }
}
