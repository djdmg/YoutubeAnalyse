<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\GoogleAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GoogleAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly RouterInterface $router,
        private readonly string $avatarDir,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'auth_google_callback'
            && $request->query->has('code');
    }

    public function authenticate(Request $request): Passport
    {
        $code = $request->query->get('code');
        [$channels, $googleUser, $tokenData] = $this->authService->handleCallbackForAuth($code);

        $request->getSession()->set('_pending_channels', $channels);
        $request->getSession()->set('_pending_token_data', $tokenData);

        return new SelfValidatingPassport(
            new UserBadge($googleUser['email'], function () use ($googleUser) {
                return $this->findOrCreateUser($googleUser);
            })
        );
    }

    private function findOrCreateUser(array $googleUser): User
    {
        $user = $this->userRepository->findByGoogleId($googleUser['id'])
            ?? $this->userRepository->findByEmail($googleUser['email']);

        $localAvatar = $this->cacheAvatar($googleUser['id'], $googleUser['picture'] ?? null);
        $isFirstUser = ($this->userRepository->count([]) === 0);

        if (!$user) {
            $user = new User();
            $user->setGoogleId($googleUser['id'])
                ->setEmail($googleUser['email'])
                ->setDisplayName($googleUser['name'])
                ->setAvatarUrl($localAvatar)
                ->setRoles($isFirstUser ? ['ROLE_ADMIN'] : ['ROLE_USER'])
                ->setIsApproved($isFirstUser); // premier user = admin approuvé automatiquement

            if ($isFirstUser) {
                $user->setApprovedAt(new \DateTimeImmutable());
            }

            $this->em->persist($user);
        } else {
            $user->setGoogleId($googleUser['id'])
                ->setDisplayName($googleUser['name'])
                ->setAvatarUrl($localAvatar ?? $user->getAvatarUrl());
        }

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        return $user;
    }

    private function cacheAvatar(string $googleId, ?string $pictureUrl): ?string
    {
        if (!$pictureUrl) {
            return null;
        }

        $filename = 'avatar_' . md5($googleId) . '.jpg';
        $localPath = $this->avatarDir . '/' . $filename;

        if (!file_exists($localPath)) {
            $imageData = @file_get_contents($pictureUrl);
            if ($imageData !== false) {
                file_put_contents($localPath, $imageData);
            } else {
                return null;
            }
        }

        return '/uploads/avatars/' . $filename;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();
        $channels  = $request->getSession()->get('_pending_channels', []);
        $tokenData = $request->getSession()->get('_pending_token_data', []);

        // Utilisateur non approuvé → page d'attente
        if (!$user->isApproved()) {
            $request->getSession()->remove('_pending_channels');
            $request->getSession()->remove('_pending_token_data');
            return new RedirectResponse($this->router->generate('pending_approval'));
        }

        $this->authService->saveTokenForUser($user, $tokenData);

        if (count($channels) === 1) {
            $this->authService->selectChannelForUser($user, $channels[0]['id'], $channels[0]['title']);
            $request->getSession()->remove('_pending_channels');
            $request->getSession()->remove('_pending_token_data');
            return new RedirectResponse($this->router->generate('dashboard'));
        }

        return new RedirectResponse($this->router->generate('auth_select_channel_page'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $request->getSession()->getFlashBag()->add('error', 'Erreur de connexion : ' . $exception->getMessage());
        return new RedirectResponse($this->router->generate('login'));
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->router->generate('login'));
    }
}
