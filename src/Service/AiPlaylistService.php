<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Video;

class AiPlaylistService
{
    public function __construct(
        private readonly AnthropicService $anthropic,
    ) {}

    /**
     * @param Video[] $videos
     * @return array{proposals: array<array{title: string, description: string, videos: array<array{youtubeId: string, title: string, reason: string}>}>}|null
     */
    public function propose(array $videos, string $userPrompt): ?array
    {
        if (empty($videos)) {
            return null;
        }

        // Cap at 80 videos to stay within token limits
        $slice = array_slice($videos, 0, 80);

        $videosList = array_map(fn(Video $v) => [
            'youtubeId'   => $v->getYoutubeId(),
            'title'       => $v->getTitle(),
            'genre'       => $v->getGenre(),
            'publishedAt' => $v->getPublishedAt()?->format('Y-m-d'),
        ], $slice);

        $prompt = $this->buildPrompt($videosList, $userPrompt);

        return $this->anthropic->callRaw($prompt, AnthropicService::MODEL_BALANCED, 4096);
    }

    private function buildPrompt(array $videos, string $userPrompt): string
    {
        $videosJson = json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $intentSection = trim($userPrompt) !== ''
            ? "Intention de l'utilisateur : \"{$userPrompt}\""
            : "L'utilisateur n'a pas donné de thème particulier. Propose des regroupements pertinents basés sur les données.";

        return <<<PROMPT
Tu es un expert en curation de playlists YouTube.

{$intentSection}

Voici les vidéos disponibles (youtubeId, title, genre, publishedAt) :
{$videosJson}

Génère exactement 3 propositions de playlists différentes et complémentaires. Pour chaque playlist :
- Un titre accrocheur (max 100 caractères)
- Une description engageante (max 300 caractères)
- Une liste ordonnée des vidéos les plus pertinentes (min 3, max 20 vidéos)
- Pour chaque vidéo, une courte raison de l'inclure (max 60 caractères)

Les 3 playlists doivent avoir des angles différents (ex : thématique, chronologique, popularité, humeur…).

Réponds UNIQUEMENT en JSON valide, sans texte avant ni après, selon ce format exact :
{
  "proposals": [
    {
      "title": "...",
      "description": "...",
      "videos": [
        {"youtubeId": "...", "title": "...", "reason": "..."},
        ...
      ]
    }
  ]
}
PROMPT;
    }
}
