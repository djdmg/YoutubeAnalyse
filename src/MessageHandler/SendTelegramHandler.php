<?php

namespace App\MessageHandler;

use App\Message\SendTelegramMessage;
use App\Repository\AppSettingRepository;
use App\Service\TelegramNotificationService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsMessageHandler]
class SendTelegramHandler
{
    public function __construct(
        private readonly HttpClientInterface  $httpClient,
        private readonly AppSettingRepository $settingRepo,
        private readonly LoggerInterface      $logger,
    ) {}

    public function __invoke(SendTelegramMessage $msg): void
    {
        $token = $this->settingRepo->get(TelegramNotificationService::SETTING_KEY);
        if (!$token) {
            $this->logger->warning('SendTelegramHandler: bot token not configured');
            return;
        }

        $response = $this->httpClient->request('POST',
            TelegramNotificationService::API_BASE . $token . '/sendMessage',
            [
                'timeout' => 10,
                'json'    => [
                    'chat_id'    => $msg->chatId,
                    'text'       => $msg->text,
                    'parse_mode' => 'MarkdownV2',
                ],
            ]
        );

        $body = $response->toArray(false);
        if (!($body['ok'] ?? false)) {
            $this->logger->warning('Telegram API error', [
                'chat_id' => $msg->chatId,
                'error'   => $body['description'] ?? 'unknown',
            ]);
        }
    }
}
