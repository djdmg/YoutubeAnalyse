<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\DailyMetricRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/analytics/relaunch', name: 'relaunch_detection')]
class RelaunchController extends AbstractController
{
    public function __construct(
        private readonly DailyMetricRepository $metricRepo,
        private readonly AiReportRepository $aiReportRepo,
    ) {}

    public function __invoke(): Response
    {
        /** @var User $user */
        $user       = $this->getUser();
        $sqlError   = null;

        try {
            $candidates = $this->metricRepo->getRelaunchCandidateStats($user);
        } catch (\Throwable $e) {
            $candidates = [];
            $sqlError   = $e->getMessage();
        }

        foreach ($candidates as &$row) {
            $video = $row['video'];
            $row['relaunch_report'] = $this->aiReportRepo->findRecentDone($video, AiReportType::RelaunchSuggestion, 720);
            $row['seo_report']      = $this->aiReportRepo->findRecentDone($video, AiReportType::SeoOptimization, 720);
            $row['title_report']    = $this->aiReportRepo->findRecentDone($video, AiReportType::TitleOptimization, 720);

            // Proposed actions list
            $actions = [];
            if ($row['is_revival']) {
                $actions[] = ['icon' => 'fa-fire', 'color' => '#f59e0b', 'text' => 'Publier un post communauté pour surfer sur la vague'];
                $actions[] = ['icon' => 'fa-scissors', 'color' => '#8b5cf6', 'text' => 'Créer un Short dérivé de la séquence la plus regardée'];
            }
            if ($row['is_hidden_gem']) {
                $actions[] = ['icon' => 'fa-search', 'color' => '#10b981', 'text' => 'Tester un nouveau titre optimisé pour la recherche'];
                $actions[] = ['icon' => 'fa-image', 'color' => '#06b6d4', 'text' => 'Générer une nouvelle miniature plus accrocheuse'];
                $actions[] = ['icon' => 'fa-share-alt', 'color' => '#3b82f6', 'text' => 'Partager sur les réseaux sociaux pour booster les impressions'];
            }
            if ($row['avg_ctr'] > 0 && $row['avg_ctr'] < 4.0) {
                $actions[] = ['icon' => 'fa-heading', 'color' => '#ec4899', 'text' => 'Optimiser le titre avec des mots-clés à fort volume'];
            }
            $row['suggested_actions'] = $actions;
        }
        unset($row);

        return $this->render('analytics/relaunch.html.twig', [
            'candidates' => $candidates,
            'sql_error'  => $sqlError,
        ]);
    }
}
