<?php

namespace App\Service;

use App\Entity\AiReport;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelegramNotificationService
{
    private const API_BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(TELEGRAM_BOT_TOKEN)%')]
        private readonly string $botToken,
    ) {}

    public function sendDailyReport(User $user, array $stats, array $topVideos, \DateTimeImmutable $date, bool $isDelayed = false): void
    {
        if (!$user->hasTelegramConfigured()) return;

        $views    = number_format((int) ($stats['views'] ?? 0), 0, ',', '\u{202F}');
        $watch    = (int) ($stats['watch_time'] ?? 0);
        $watchFmt = $watch >= 60 ? round($watch / 60, 1) . 'h' : $watch . ' min';
        $subs     = (int) ($stats['subscribers'] ?? 0);
        $ctr      = $stats['avg_ctr'] !== null ? round((float) $stats['avg_ctr'], 1) . '%' : '—';

        $lines = [];
        $lines[] = sprintf('📊 *Rapport quotidien — %s*', $date->format('d/m/Y'));
        if ($isDelayed) {
            $lines[] = '⚠ _Données en retard (sync YouTube)_';
        }
        $lines[] = '';
        $lines[] = sprintf('👁 Vues : *%s*', $views);
        $lines[] = sprintf('⏱ Watch time : *%s*', $watchFmt);
        $lines[] = sprintf('👤 Abonnés : *%s%d*', $subs >= 0 ? '+' : '', $subs);
        $lines[] = sprintf('🎯 CTR : *%s*', $ctr);

        if (!empty($topVideos)) {
            $lines[] = '';
            $lines[] = '🎬 *Top vidéos*';
            foreach (array_slice($topVideos, 0, 5) as $i => $item) {
                $title  = mb_strimwidth($item['video']->getTitle(), 0, 40, '…');
                $vViews = number_format((int) $item['views'], 0, ',', '\u{202F}');
                $lines[] = sprintf('%d\. %s — %s vues', $i + 1, $this->escape($title), $vViews);
            }
        }

        $this->send($user->getTelegramChatId(), implode("\n", $lines));
    }

    /** @param AiReport[] $reports */
    public function sendAiRecommendations(User $user, array $reports): void
    {
        if (!$user->hasTelegramConfigured() || empty($reports)) return;

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

        $this->send($user->getTelegramChatId(), implode("\n", $lines));
    }

    public function sendSyncSummary(User $user, array $result, string $channel): void
    {
        if (!$user->hasTelegramConfigured()) return;

        $lines = [
            sprintf('🔄 *Sync terminée — %s*', $this->escape($channel)),
            '',
            sprintf('🎬 Vidéos : *%d*', $result['videos_synced'] ?? 0),
            sprintf('💬 Commentaires : *%d*', $result['comments_synced'] ?? 0),
            sprintf('🔍 Termes de recherche : *%d*', $result['search_terms_synced'] ?? 0),
        ];

        $this->send($user->getTelegramChatId(), implode("\n", $lines));
    }

    private function send(string $chatId, string $text): void
    {
        if ($this->botToken === '') {
            $this->logger->warning('Telegram bot token not configured — skipping notification');
            return;
        }

        try {
            $this->httpClient->request('POST', self::API_BASE . $this->botToken . '/sendMessage', [
                'json' => [
                    'chat_id'    => $chatId,
                    'text'       => $text,
                    'parse_mode' => 'MarkdownV2',
                ],
            ])->getContent();
        } catch (\Throwable $e) {
            $this->logger->warning('Telegram send failed', ['chat_id' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    private function escape(string $text): string
    {
        // MarkdownV2 requires escaping these characters
        return preg_replace('/([_*\[\]()~`>#+\-=|{}.!\\\\])/', '\\\\$1', $text);
    }
}
