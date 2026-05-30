<?php

namespace App\Message;

final readonly class SendEmailMessage
{
    public function __construct(
        public string $to,
        public string $fromEmail,
        public string $fromName,
        public string $subject,
        public string $htmlBody,
    ) {}
}
