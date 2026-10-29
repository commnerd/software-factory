<?php

namespace App\Services\Execution;

final class ExecutionRequest
{
    /**
     * @param  string  $workspace  directory (as seen by this app) mounted at /workspace
     * @param  array<string, string>  $env
     * @param  array<string, string>  $mounts  extra read-only bind mounts: local path => container path
     */
    public function __construct(
        public readonly string $workspace,
        public readonly string $image,
        public readonly string $command,
        public readonly int $timeout = 900,
        public readonly array $env = [],
        public readonly array $mounts = [],
        public readonly ?string $phase = null,
    ) {}
}
