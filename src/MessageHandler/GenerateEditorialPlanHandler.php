<?php

namespace App\MessageHandler;

use App\Entity\AiReport;
use App\Entity\User;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Message\GenerateEditorialPlanMessage;
use App\Repository\DailyMetricRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSearchTermRepository;
use App\Service\AiProviderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class GenerateEditorialPlanHandler
{
    public function __construct(
        private readonly AiProviderFactory        $ai,
        private readonly CacheInterface           $cache,
        private readonly EntityManagerInterface   $em,
        private readonly VideoRepository          $videoRepo,
        private readonly DailyMetricRepository    $metricRepo,
        private readonly VideoSearchTermRepository $searchTermRepo,
    ) {}

    public function __invoke(GenerateEditorialPlanMessage $msg): void
    {
        $cacheKey = 'editorial_plan_' . $msg->jobId;
        $report   = new AiReport();
        $report->setType(AiReportType::EditorialPlanning);
        $report->setStatus(AiReportStatus::Pending);

        try {
            /** @var User $user */
            $user      = $this->em->find(User::class, $msg->userId);
            $topVideos = $this->videoRepo->findTopPerformingForUser($user, 8);
            $stats30   = $this->metricRepo->getGlobalStatsForUser($user, 30);

            // Build top video summary
            $videoSummary = '';
            $listStats    = $this->metricRepo->getListStatsForUser($user);
            foreach ($topVideos as $v) {
                $s = $listStats[$v->getId()] ?? [];
                $videoSummary .= sprintf(
                    "- \"%s\" — %s vues, CTR %.2f%%\n",
                    $v->getTitle(),
                    number_format((int)($s['total_views'] ?? 0), 0, ',', ' '),
                    (float)($s['avg_ctr'] ?? 0)
                );
            }

            // Build search terms summary
            $allSearchTerms = [];
            foreach ($topVideos as $v) {
                $terms = $this->searchTermRepo->findTopForVideo($v, 5);
                foreach ($terms as $t) {
                    $allSearchTerms[] = $t->getTerm();
                }
            }
            $searchTermsSummary = implode(', ', array_unique(array_slice($allSearchTerms, 0, 20)));

            $prompt = <<<PROMPT
Tu es un stratège de contenu YouTube expert. Analyse les données de cette chaîne et génère 10 idées de vidéos classées par potentiel.

=== DONNÉES DE LA CHAÎNE ===
Vues 30 derniers jours : {$stats30['total_views']}
Watch time 30j (minutes) : {$stats30['total_watch_time']}
CTR moyen 30j : {$stats30['avg_ctr']}%

=== TOP VIDÉOS (par vues totales) ===
{$videoSummary}

=== TERMES DE RECHERCHE QUI AMÈNENT DU TRAFIC ===
{$searchTermsSummary}

=== CONSIGNES ===
Génère exactement 10 idées de vidéos sous forme de tableau JSON. Pour chaque idée :
- "title" : titre accrocheur (max 70 chars), optimisé SEO, doit donner envie de cliquer
- "hook" : première phrase d'accroche (15 mots max)
- "format" : "tutoriel" | "liste" | "vlog" | "comparaison" | "réaction" | "analyse" | "short"
- "potential" : "élevé" | "moyen" | "test"
- "reason" : pourquoi cette vidéo performera (1 phrase, basée sur les données)
- "keywords" : 3 mots-clés SEO principaux

Classe les idées du plus grand potentiel au plus faible. Réponds UNIQUEMENT avec le JSON, sans prose, sans markdown:
[
  {"title":"...","hook":"...","format":"...","potential":"élevé","reason":"...","keywords":["...","...","..."]}
]
PROMPT;

            $items = $this->ai->callJson($prompt, [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'    => ['type' => 'string'],
                        'hook'     => ['type' => 'string'],
                        'format'   => ['type' => 'string'],
                        'potential'=> ['type' => 'string'],
                        'reason'   => ['type' => 'string'],
                        'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                    'required' => ['title', 'hook', 'format', 'potential', 'reason'],
                ],
            ], $msg->model, $report);

            if (!is_array($items)) {
                $raw   = $this->ai->callText($prompt, $msg->model);
                $items = $this->extractJsonArray($raw);
            }

            if (!is_array($items)) {
                throw new \RuntimeException('Impossible de générer le planning éditorial.');
            }

            $ideas = array_slice(array_values(array_filter($items, fn($i) => is_array($i) && isset($i['title']))), 0, 10);

            $report->setStatus(AiReportStatus::Done);
            $report->setContent(json_encode($ideas, JSON_UNESCAPED_UNICODE));
            $report->setPayload(['count' => count($ideas)]);
            $this->em->persist($report);
            $this->em->flush();

            $this->store($cacheKey, ['status' => 'done', 'ideas' => $ideas]);

        } catch (\Throwable $e) {
            $report->setStatus(AiReportStatus::Failed);
            $this->em->persist($report);
            $this->em->flush();
            $this->store($cacheKey, ['status' => 'error', 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function extractJsonArray(string $text): mixed
    {
        $text  = preg_replace('/^```(?:json)?\s*/im', '', $text);
        $text  = preg_replace('/\s*```\s*$/im', '', $text);
        $start = strpos($text, '[');
        $end   = strrpos($text, ']');
        if ($start === false || $end === false || $end <= $start) return null;
        return json_decode(substr($text, $start, $end - $start + 1), true);
    }

    private function store(string $key, array $data): void
    {
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($data) {
            $item->expiresAfter(1800);
            return $data;
        });
    }
}
