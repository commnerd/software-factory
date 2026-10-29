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

    /** The stage ended the whole run itself (e.g. an empty backlog closed the cycle); nothing more to do now. */
    public static function skipped(): self
    {
        return new self('skipped');
    }

    public function isCompleted(): bool
    {
        return $this->state === 'completed';
    }

    public function isSkipped(): bool
    {
        return $this->state === 'skipped';
    }
}
