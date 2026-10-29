<?php

namespace App\Services\Ai;

final class CompletionRequest
{
    public function __construct(
        public readonly string $model,
        public readonly string $prompt,
        public readonly ?string $system = null,
        public readonly array $parameters = [],
        public readonly ?string $step = null,
    ) {}
}
