<?php

namespace App\Services\Execution;

final class ExecutionResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly int $durationMs = 0,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
