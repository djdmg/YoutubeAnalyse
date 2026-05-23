<?php

namespace App\Controller;

use App\Repository\VideoRepository;
use App\Service\AiPlaylistService;
use App\Service\YouTubePlaylistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/playlists')]
class PlaylistController extends AbstractController
{
    public function __construct(
        private readonly VideoRepository $videoRepo,
        private readonly AiPlaylistService $aiPlaylistService,
        private readonly YouTubePlaylistService $youtubePlaylistService,
    ) {}

    #[Route('', name: 'playlists_create', methods: ['GET'])]
    public function create(): Response
    {
        $videos = $this->videoRepo->findForUser($this->getUser());

        return $this->render('playlist/create.html.twig', [
            'video_count' => count($videos),
        ]);
    }

    #[Route('/propose', name: 'playlists_propose', methods: ['POST'])]
    public function propose(Request $request): JsonResponse
    {
        $data       = json_decode($request->getContent(), true);
        $userPrompt = trim($data['prompt'] ?? '');

        $videos   = $this->videoRepo->findForUser($this->getUser());
        $result   = $this->aiPlaylistService->propose($videos, $userPrompt);

        if (!$result || empty($result['proposals'])) {
            return $this->json(['error' => "L'IA n'a pas pu générer de propositions. Réessayez."], 500);
        }

        // Attach thumbnails from DB so the front-end can display them
        $thumbMap = [];
        foreach ($videos as $v) {
            $thumbMap[$v->getYoutubeId()] = $v->getThumbnailUrl();
        }

        foreach ($result['proposals'] as &$proposal) {
            foreach ($proposal['videos'] as &$pv) {
                $pv['thumbnailUrl'] = $thumbMap[$pv['youtubeId']] ?? null;
            }
        }
        unset($proposal, $pv);

        return $this->json($result);
    }

    #[Route('/confirm', name: 'playlists_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $data        = json_decode($request->getContent(), true);
        $title       = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');
        $videoIds    = $data['videoIds'] ?? [];
        $privacy     = in_array($data['privacy'] ?? '', ['public', 'unlisted', 'private']) ? $data['privacy'] : 'private';

        if (!$title || empty($videoIds)) {
            return $this->json(['error' => 'Titre et au moins une vidéo requis.'], 400);
        }

        $user = $this->getUser();

        try {
            $playlistId = $this->youtubePlaylistService->createPlaylist($user, $title, $description, $privacy);
            $this->youtubePlaylistService->addVideosToPlaylist($user, $playlistId, $videoIds);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur YouTube API : ' . $e->getMessage()], 500);
        }

        $playlistUrl = 'https://www.youtube.com/playlist?list=' . $playlistId;

        return $this->json(['playlistId' => $playlistId, 'playlistUrl' => $playlistUrl]);
    }
}
