<?php

namespace App\Service;

use App\Entity\GoogleToken;
use App\Entity\User;
use App\Repository\GoogleTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client;

class GoogleAuthService
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly EntityManagerInterface $em,
        private readonly GoogleTokenRepository $tokenRepository,
    ) {}

    public function createClient(): Client
    {
        $client = new Client();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        $client->addScope('https://www.googleapis.com/auth/youtube.readonly');
        $client->addScope('https://www.googleapis.com/auth/youtube.force-ssl');
        $client->addScope('https://www.googleapis.com/auth/yt-analytics.readonly');
        $client->addScope('https://www.googleapis.com/auth/yt-analytics-monetary.readonly');
        $client->addScope('https://www.googleapis.com/auth/userinfo.email');
        $client->addScope('https://www.googleapis.com/auth/userinfo.profile');
        $client->setAccessType('offline');

        return $client;
    }

    public function getAuthUrl(): string
    {
        $client = $this->createClient();
        // 'consent' only on first auth — once the token is stored, renewals use the refresh token silently.
        // If the user has no stored token yet, Google will ask for consent anyway (offline + first time).
        // Using 'select_account' lets the user switch Google accounts without forcing re-consent.
        $client->setPrompt('select_account');
        return $client->createAuthUrl();
    }

    /**
     * Exchanges code for token, fetches Google user info and channels list.
     * Returns [$channels, $googleUser, $tokenData]
     */
    public function handleCallbackForAuth(string $code): array
    {
        $client = $this->createClient();
        $tokenData = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($tokenData['error'])) {
            throw new \RuntimeException('OAuth error: ' . ($tokenData['error_description'] ?? $tokenData['error']));
        }

        $client->setAccessToken($tokenData);

        // Fetch Google user profile
        $oauth2 = new \Google\Service\Oauth2($client);
        $googleUser = $oauth2->userinfo->get();

        $userInfo = [
            'id'      => $googleUser->getId(),
            'email'   => $googleUser->getEmail(),
            'name'    => $googleUser->getName(),
            'picture' => $googleUser->getPicture(),
        ];

        // Fetch YouTube channels
        $youtube = new \Google\Service\YouTube($client);
        $channelResponse = $youtube->channels->listChannels('id,snippet,statistics', ['mine' => true]);

        $channels = [];
        foreach ($channelResponse->getItems() as $channel) {
            $channels[] = [
                'id'          => $channel->getId(),
                'title'       => $channel->getSnippet()->getTitle(),
                'thumbnail'   => $channel->getSnippet()->getThumbnails()?->getDefault()?->getUrl(),
                'subscribers' => (int) $channel->getStatistics()->getSubscriberCount(),
                'videos'      => (int) $channel->getStatistics()->getVideoCount(),
            ];
        }

        return [$channels, $userInfo, $tokenData];
    }

    public function saveTokenForUser(User $user, array $tokenData): GoogleToken
    {
        $token = $this->tokenRepository->findPendingForUser($user) ?? new GoogleToken();
        $token->setUser($user)
            ->setChannelId('pending')
            ->setAccessToken($tokenData['access_token'])
            ->setRefreshToken($tokenData['refresh_token'] ?? $token->getRefreshToken())
            ->setExpiresAt(new \DateTimeImmutable('@' . ($tokenData['created'] + $tokenData['expires_in'])))
            ->setRawToken(json_encode($tokenData))
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function selectChannelForUser(User $user, string $channelId, string $channelTitle = ''): void
    {
        $token = $this->tokenRepository->findPendingForUser($user);
        if (!$token) {
            throw new \RuntimeException('Session expirée. Veuillez vous reconnecter.');
        }
        $token->setChannelId($channelId)
            ->setChannelTitle($channelTitle)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    public function getAuthenticatedClientForUser(User $user): ?Client
    {
        $token = $this->tokenRepository->findForUser($user);
        if (!$token) {
            return null;
        }

        $client = $this->createClient();
        $rawToken = json_decode($token->getRawToken(), true);
        $client->setAccessToken($rawToken);

        if ($client->isAccessTokenExpired() && $token->getRefreshToken()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($token->getRefreshToken());
            if (!isset($newToken['error'])) {
                $token->setAccessToken($newToken['access_token'])
                    ->setExpiresAt(new \DateTimeImmutable('@' . ($newToken['created'] + $newToken['expires_in'])))
                    ->setRawToken(json_encode(array_merge($rawToken, $newToken)))
                    ->setUpdatedAt(new \DateTimeImmutable());
                $this->em->flush();
                $client->setAccessToken($newToken);
            }
        }

        return $client->isAccessTokenExpired() ? null : $client;
    }

    public function isAuthenticatedUser(User $user): bool
    {
        return $this->getAuthenticatedClientForUser($user) !== null;
    }

    public function revokeTokenForUser(User $user): void
    {
        $token = $this->tokenRepository->findForUser($user);
        if ($token) {
            $this->em->remove($token);
            $this->em->flush();
        }
    }

    // Keep backward compat for non-user-aware calls
    public function getAuthenticatedClient(): ?Client
    {
        return null;
    }

    public function isAuthenticated(): bool
    {
        return false;
    }
}
