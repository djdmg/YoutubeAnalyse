<?php

namespace App\Message;

final readonly class SendTelegramMessage
{
    public function __construct(
        public string $chatId,
        public string $text,
    ) {}
}
