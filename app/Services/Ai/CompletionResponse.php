<?php

namespace App\Services\Ai;

final class CompletionResponse
{
    public function __construct(
        public readonly string $content,
        public readonly ?string $thinking = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}
}
