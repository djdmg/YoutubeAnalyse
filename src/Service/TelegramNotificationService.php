<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Entity\User;
use App\Repository\AppSettingRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramNotificationService
{
    private const API_BASE   = 'https://api.telegram.org/bot';
    public  const SETTING_KEY = 'telegram_bot_token';

    public function __construct(
        private readonly HttpClientInterface   $httpClient,
        private readonly AppSettingRepository  $settingRepo,
        private readonly LoggerInterface       $logger,
    ) {}

    private function botToken(): ?string
    {
        $token = $this->settingRepo->get(self::SETTING_KEY);
        return ($token !== null && $token !== '') ? $token : null;
    }

    /** Sends a test message; returns null on success or an error string on failure. */
    public function sendTest(User $user): ?string
    {
        if (!$this->botToken()) {
            return 'Token bot Telegram non configuré (section Admin → Paramètres).';
        }
        if (!$user->hasTelegramConfigured()) {
            return 'Chat ID non renseigné.';
        }

        return $this->sendRaw($user->getTelegramChatId(),
            "✅ *YouTube Analyse* — test de notification Telegram réussi\\!"
        );
    }

    public function sendDailyReport(User $user, array $stats, array $topVideos, \DateTimeImmutable $date, bool $isDelayed = false): void
    {
        if (!$user->hasTelegramConfigured() || !$this->botToken()) return;

        $views    = number_format((int) ($stats['views'] ?? 0), 0, ',', "\u{202F}");
        $watch    = (int) ($stats['watch_time'] ?? 0);
        $watchFmt = $watch >= 60 ? round($watch / 60, 1) . 'h' : $watch . ' min';
        $subs     = (int) ($stats['subscribers'] ?? 0);
        $ctr      = $stats['avg_ctr'] !== null ? round((float) $stats['avg_ctr'], 1) . '%' : '—';

        $lines = [];
        $lines[] = sprintf('📊 *Rapport quotidien — %s*', $this->escape($date->format('d/m/Y')));
        if ($isDelayed) {
            $lines[] = '⚠ _Données en retard \\(sync YouTube\\)_';
        }
        $lines[] = '';
        $lines[] = sprintf('👁 Vues : *%s*', $this->escape($views));
        $lines[] = sprintf('⏱ Watch time : *%s*', $this->escape($watchFmt));
        $lines[] = sprintf('👤 Abonnés : *%s%d*', $subs >= 0 ? '\\+' : '', $subs);
        $lines[] = sprintf('🎯 CTR : *%s*', $this->escape($ctr));

        if (!empty($topVideos)) {
            $lines[] = '';
            $lines[] = '🎬 *Top vidéos*';
            foreach (array_slice($topVideos, 0, 5) as $i => $item) {
                $title  = mb_strimwidth($item['video']->getTitle(), 0, 40, '…');
                $vViews = number_format((int) $item['views'], 0, ',', "\u{202F}");
                $lines[] = sprintf('%d\. %s — %s vues', $i + 1, $this->escape($title), $this->escape($vViews));
            }
        }

        $this->sendRaw($user->getTelegramChatId(), implode("\n", $lines));
    }

    public function sendWeeklyReport(User $user, array $stats, array $topVideos, \DateTimeImmutable $weekStart, \DateTimeImmutable $weekEnd): void
    {
        if (!$user->hasTelegramConfigured() || !$this->botToken()) return;

        $views    = number_format((int) ($stats['views'] ?? 0), 0, ',', "\u{202F}");
        $watch    = (int) ($stats['watch_time'] ?? 0);
        $watchFmt = $watch >= 60 ? round($watch / 60, 1) . 'h' : $watch . ' min';
        $subs     = (int) ($stats['subscribers'] ?? 0);

        $lines = [
            sprintf('📅 *Bilan semaine — %s au %s*', $this->escape($weekStart->format('d/m')), $this->escape($weekEnd->format('d/m/Y'))),
            '',
            sprintf('👁 Vues : *%s*', $this->escape($views)),
            sprintf('⏱ Watch time : *%s*', $this->escape($watchFmt)),
            sprintf('👤 Abonnés : *%s%d*', $subs >= 0 ? '\\+' : '', $subs),
        ];

        if (!empty($topVideos)) {
            $lines[] = '';
            $lines[] = '🏆 *Top vidéos de la semaine*';
            foreach (array_slice($topVideos, 0, 3) as $i => $item) {
                $title  = mb_strimwidth($item['video']->getTitle(), 0, 40, '…');
                $vViews = number_format((int)$item['total_views'], 0, ',', "\u{202F}");
                $lines[] = sprintf('%d\. %s — %s vues', $i + 1, $this->escape($title), $this->escape($vViews));
            }
        }

        $this->sendRaw($user->getTelegramChatId(), implode("\n", $lines));
    }

    /** @param AiReport[] $reports */
    public function sendAiRecommendations(User $user, array $reports): void
    {
        if (!$user->hasTelegramConfigured() || !$this->botToken() || empty($reports)) return;

        $lines   = ['🤖 *Nouvelles analyses IA disponibles*', ''];
        $grouped = [];
        foreach ($reports as $r) {
            $grouped[$r->getVideo()->getTitle()][] = $r->getType()->label();
        }
        foreach ($grouped as $title => $types) {
            $lines[] = sprintf('▸ _%s_', $this->escape(mb_strimwidth($title, 0, 50, '…')));
            foreach ($types as $t) {
                $lines[] = '   • ' . $this->escape($t);
            }
        }

        $this->sendRaw($user->getTelegramChatId(), implode("\n", $lines));
    }

    public function sendSyncSummary(User $user, array $result, string $channel): void
    {
        if (!$user->hasTelegramConfigured() || !$this->botToken()) return;

        $lines = [
            sprintf('🔄 *Sync terminée — %s*', $this->escape($channel)),
            '',
            sprintf('🎬 Vidéos : *%d*', $result['videos_synced'] ?? 0),
            sprintf('💬 Commentaires : *%d*', $result['comments_synced'] ?? 0),
            sprintf('🔍 Termes de recherche : *%d*', $result['search_terms_synced'] ?? 0),
        ];

        $this->sendRaw($user->getTelegramChatId(), implode("\n", $lines));
    }

    /** Returns null on success, error string on failure. */
    private function sendRaw(string $chatId, string $text): ?string
    {
        $token = $this->botToken();
        if (!$token) {
            return 'Token bot Telegram non configuré.';
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE . $token . '/sendMessage', [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'MarkdownV2',
                ],
            ]);
            $body = $response->toArray(false);
            if (!($body['ok'] ?? false)) {
                $err = $body['description'] ?? 'Erreur inconnue';
                $this->logger->warning('Telegram API error', ['chat_id' => $chatId, 'error' => $err]);
                return $err;
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram send failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
            return $e->getMessage();
        }
    }

    private function escape(string $text): string
    {
        return preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $text);
    }
}
