<?php

namespace App\Message;

final class RunAiAnalysisMessage
{
    public function __construct(
        public readonly int     $userId,
        public readonly string  $jobId,
        public readonly ?string $youtubeId = null, // null = all videos
        public readonly ?string $type      = null, // null = all types
        public readonly bool    $force     = false,
    ) {}
}
