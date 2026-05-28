<?php

namespace App\Message;

final class GenerateGoalSuggestionsMessage
{
    public function __construct(
        public readonly string $jobId,
        public readonly int    $userId,
        public readonly int    $subscribers,
        public readonly int    $views30,
        public readonly int    $views7,
        public readonly int    $watchTime30,
        public readonly float  $avgCtr,
        public readonly string $existingGoals,
        public readonly string $today,
        public readonly string $model = 'fast',
    ) {}
}
