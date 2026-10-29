<?php

namespace App\Services\Execution\Contracts;

use App\Services\Execution\ExecutionRequest;
use App\Services\Execution\ExecutionResult;

interface Executor
{
    public function run(ExecutionRequest $request): ExecutionResult;
}
