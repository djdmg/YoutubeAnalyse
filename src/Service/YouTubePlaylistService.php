<?php

namespace App\Service;

use App\Entity\User;
use Google\Service\YouTube;
use Google\Service\YouTube\Playlist;
use Google\Service\YouTube\PlaylistItem;
use Google\Service\YouTube\PlaylistItemSnippet;
use Google\Service\YouTube\PlaylistSnippet;
use Google\Service\YouTube\PlaylistStatus;
use Google\Service\YouTube\ResourceId;
use Psr\Log\LoggerInterface;

class YouTubePlaylistService
{
    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly QuotaGuardService $quotaGuard,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Creates a playlist on YouTube and returns its ID.
     * Quota cost: 50 units.
     */
    public function createPlaylist(User $user, string $title, string $description, string $privacy = 'private'): string
    {
        $this->quotaGuard->consume(50);

        $youtube = new YouTube($this->authService->getAuthenticatedClientForUser($user));

        $snippet = new PlaylistSnippet();
        $snippet->setTitle($title);
        $snippet->setDescription($description);

        $status = new PlaylistStatus();
        $status->setPrivacyStatus($privacy);

        $playlist = new Playlist();
        $playlist->setSnippet($snippet);
        $playlist->setStatus($status);

        $response = $youtube->playlists->insert('snippet,status', $playlist);

        $this->logger->info('YouTube playlist created', ['id' => $response->getId(), 'title' => $title]);

        return $response->getId();
    }

    /**
     * Adds a video to an existing playlist.
     * Quota cost: 50 units per video.
     *
     * @param string[] $videoIds YouTube video IDs
     */
    public function addVideosToPlaylist(User $user, string $playlistId, array $videoIds): void
    {
        $youtube = new YouTube($this->authService->getAuthenticatedClientForUser($user));

        foreach ($videoIds as $videoId) {
            $this->quotaGuard->consume(50);

            $resourceId = new ResourceId();
            $resourceId->setKind('youtube#video');
            $resourceId->setVideoId($videoId);

            $itemSnippet = new PlaylistItemSnippet();
            $itemSnippet->setPlaylistId($playlistId);
            $itemSnippet->setResourceId($resourceId);

            $item = new PlaylistItem();
            $item->setSnippet($itemSnippet);

            $youtube->playlistItems->insert('snippet', $item);

            $this->logger->info('Video added to playlist', ['playlistId' => $playlistId, 'videoId' => $videoId]);
        }
    }
}
