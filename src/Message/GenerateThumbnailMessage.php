<?php

namespace App\Message;

final class GenerateThumbnailMessage
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $videoId,
        public readonly string $model,
        public readonly string $prompt,
    ) {}
}
