<?php

namespace App\Services\Ai;

final class CompiledPrompt
{
    /** @param  list<string>  $missing  variables referenced by the template but not supplied */
    public function __construct(
        public readonly ?string $system,
        public readonly string $user,
        public readonly array $missing = [],
    ) {}
}
