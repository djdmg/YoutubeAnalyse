<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Entity\User;
use App\Enum\AiReportType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class EmailNotificationService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Sends a digest email with all new AI recommendations.
     *
     * @param AiReport[] $reports
     */
    public function sendAiRecommendations(User $user, array $reports): ?string
    {
        if (empty($reports) || !$user->hasSmtpConfigured()) return null;

        $html = $this->twig->render('email/ai_recommendations.html.twig', [
            'user'    => $user,
            'reports' => $reports,
            'date'    => new \DateTimeImmutable(),
        ]);

        $email = (new Email())
            ->from(new Address($user->getSmtpUser(), 'YouTube Analyse'))
            ->to($user->getNotifEmail())
            ->subject(sprintf('🎬 %d nouvelles recommandations IA — %s', count($reports), (new \DateTimeImmutable())->format('d/m/Y')))
            ->html($html);

        return $this->send($user, $email);
    }

    public function sendSyncSummary(User $user, array $result, string $channel, int $quotaUsed): ?string
    {
        if (!$user->hasSmtpConfigured()) return null;

        $html = $this->twig->render('email/sync_summary.html.twig', [
            'user'       => $user,
            'result'     => $result,
            'channel'    => $channel,
            'quota_used' => $quotaUsed,
            'date'       => new \DateTimeImmutable(),
        ]);

        $email = (new Email())
            ->from(new Address($user->getSmtpUser(), 'YouTube Analyse'))
            ->to($user->getNotifEmail())
            ->subject(sprintf('🔄 Sync YouTube — %d vidéos, %d commentaires — %s',
                $result['videos_synced'],
                $result['comments_synced'],
                (new \DateTimeImmutable())->format('d/m/Y H:i')
            ))
            ->html($html);

        return $this->send($user, $email);
    }

    public function sendDailyReport(User $user, array $stats, array $topVideos, \DateTimeImmutable $date, bool $isDelayed = false): ?string
    {
        if (!$user->hasSmtpConfigured()) return null;

        $html = $this->twig->render('email/daily_report.html.twig', [
            'user'       => $user,
            'stats'      => $stats,
            'top_videos' => $topVideos,
            'date'       => $date,
            'is_delayed' => $isDelayed,
        ]);

        $subject = $isDelayed
            ? sprintf('📊 Rapport du %s (retard sync) — %s vues', $date->format('d/m/Y'), number_format((int) ($stats['views'] ?? 0), 0, ',', ' '))
            : sprintf('📊 Rapport du %s — %s vues', $date->format('d/m/Y'), number_format((int) ($stats['views'] ?? 0), 0, ',', ' '));

        $email = (new Email())
            ->from(new Address($user->getSmtpUser(), 'YouTube Analyse'))
            ->to($user->getNotifEmail())
            ->subject($subject)
            ->html($html);

        return $this->send($user, $email);
    }

    public function sendTestEmail(User $user): ?string
    {
        $fakeReports = $this->buildFakeReports();

        $html = $this->twig->render('email/ai_recommendations.html.twig', [
            'user'    => $user,
            'reports' => $fakeReports,
            'date'    => new \DateTimeImmutable(),
            'is_test' => true,
        ]);

        $email = (new Email())
            ->from(new Address($user->getSmtpUser(), 'YouTube Analyse'))
            ->to($user->getNotifEmail())
            ->subject('🧪 [TEST] Recommandations IA — YouTube Analyse')
            ->html($html);

        return $this->send($user, $email);
    }

    private function buildFakeReports(): array
    {
        return [
            [
                'type'  => ['value' => 'title_optimization', 'label' => 'Optimisation titre'],
                'video' => ['title' => 'House Mix - Deep Vibes Vol. 3 | 2h Set'],
                'payload' => [
                    'diagnostic'           => 'Le titre manque de mots-clés spécifiques au genre. "House Mix" est trop générique et ne se démarque pas dans les résultats de recherche YouTube.',
                    'suggestions_titres'   => [
                        'Deep House Mix 2025 🎧 2h Set | Melodic & Organic House',
                        'Deep Vibes Vol.3 — Best Deep House Mix | Progressive Journey 2025',
                        '2H Deep House Set 2025 | Underground Vibes — Deep Vibes Vol.3',
                    ],
                    'mots_cles_manquants'  => ['deep house 2025', 'melodic house', 'organic house', 'underground'],
                    'suggestions_description' => 'Deep House Mix 2025 — 2 heures de sélection soignée entre Melodic House et Organic House. Perfect for late night sessions.',
                ],
            ],
            [
                'type'  => ['value' => 'comment_analysis', 'label' => 'Analyse commentaires'],
                'video' => ['title' => 'Techno Set — Berlin Underground 2025'],
                'payload' => [
                    'sentiment_global'        => 'positif',
                    'score_sentiment'         => 0.87,
                    'demandes_tracklist'      => true,
                    'themes_recurrents'       => ['tracklist demandée', 'qualité sonore', 'énergie du set', 'retour live'],
                    'resume'                  => 'L\'audience apprécie fortement l\'énergie du set et la sélection musicale. Nombreuses demandes de tracklist. Plusieurs commentaires mentionnent la qualité audio exceptionnelle.',
                    'commentaires_prioritaires' => [
                        ['texte' => 'Incroyable set, besoin de la tracklist svp !', 'raison' => 'Forte demande récurrente'],
                        ['texte' => 'La qualité du son est vraiment top, bravo', 'raison' => 'Feedback positif sur la production'],
                    ],
                ],
            ],
            [
                'type'  => ['value' => 'anomaly', 'label' => 'Détection anomalie'],
                'video' => ['title' => 'Morning Rave — Sunrise Session'],
                'payload' => [
                    'type'              => 'surperformance',
                    'cause_probable'    => 'La vidéo a été partagée sur plusieurs groupes Facebook spécialisés Techno, générant un pic de trafic externe inhabituel. Le taux de clic est 2,3× supérieur à la moyenne.',
                    'action_recommandee' => 'Publiez un clip teaser de 60 secondes sur Instagram Reels et TikTok pour capitaliser sur le momentum actuel.',
                ],
            ],
            [
                'type'  => ['value' => 'prediction', 'label' => 'Prédiction J+30'],
                'video' => ['title' => 'Progressive House Journey — 90 min'],
                'payload' => [
                    'vues_estimees_j30' => 4200,
                    'fourchette'        => ['min' => 2800, 'max' => 6100],
                    'confiance'         => 'moyenne',
                    'facteurs'          => [
                        'CTR initial dans la moyenne de la chaîne',
                        'Watch time légèrement en dessous des meilleures performances',
                        'Bonne rétention au-delà des 30 premières minutes',
                    ],
                ],
            ],
            [
                'type'  => ['value' => 'upload_schedule', 'label' => 'Calendrier de publication'],
                'video' => null,
                'payload' => [
                    'meilleur_jour'  => 'vendredi',
                    'meilleure_heure' => '18h00',
                    'analyse'        => 'Vos vidéos publiées le vendredi entre 17h et 19h génèrent en moyenne 40% de vues supplémentaires sur les 48 premières heures. L\'audience est active en fin de semaine et le dimanche.',
                    'jours_a_eviter' => ['lundi', 'mardi'],
                    'confiance'      => 'élevée',
                ],
            ],
        ];
    }

    private function send(User $user, Email $email): ?string
    {
        try {
            $transport = Transport::fromDsn($this->buildDsn($user));
            $mailer    = new Mailer($transport);
            $mailer->send($email);
            $this->logger->info('Email sent', ['to' => $user->getNotifEmail()]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->error('Email send failed', ['error' => $e->getMessage()]);
            return $e->getMessage();
        }
    }

    private function buildDsn(User $user): string
    {
        $scheme   = $user->getSmtpEncryption() === 'ssl' ? 'smtps' : 'smtp';
        $userEnc  = rawurlencode($user->getSmtpUser() ?? '');
        $passEnc  = rawurlencode($user->getSmtpPassword() ?? '');
        $host     = $user->getSmtpHost();
        $port     = $user->getSmtpPort() ?? 587;

        return sprintf('%s://%s:%s@%s:%d', $scheme, $userEnc, $passEnc, $host, $port);
    }
}
