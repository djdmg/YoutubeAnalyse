<?php

namespace App\Message;

final readonly class GenerateEditorialPlanMessage
{
    public function __construct(
        public string $jobId,
        public int    $userId,
        public string $model,
    ) {}
}
