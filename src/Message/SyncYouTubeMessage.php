<?php

namespace App\Message;

final class SyncYouTubeMessage
{
    public function __construct(
        public readonly int $userId,
        public readonly string $jobId,
    ) {}
}
