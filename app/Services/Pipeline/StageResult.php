<?php

namespace App\Services\Pipeline;

final class StageResult
{
    private function __construct(public readonly string $state) {}

    public static function completed(): self
    {
        return new self('completed');
    }

    /** Execution pauses until a human decides an approval gate. */
    public static function awaitingApproval(): self
    {
        return new self('awaiting_approval');
    }

    public function isCompleted(): bool
    {
        return $this->state === 'completed';
    }
}
