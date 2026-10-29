<?php

namespace App\Services\Ai\Contracts;

use App\Services\Ai\CompletionRequest;
use App\Services\Ai\CompletionResponse;

interface AiProvider
{
    /** @throws \App\Exceptions\FactoryException on transport / provider errors */
    public function complete(CompletionRequest $request): CompletionResponse;
}
