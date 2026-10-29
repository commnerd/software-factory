<?php

namespace App\Services\Execution;

use App\Services\Execution\Contracts\Executor;

/** Simulates a TDD lifecycle: red fails, everything else passes. */
class FakeExecutor implements Executor
{
    public function run(ExecutionRequest $request): ExecutionResult
    {
        if ($request->phase === 'red') {
            return new ExecutionResult(1, "FAIL  tests/GreetingTest\n  ✕ greets by name\n  ✕ falls back to World\n\nTests: 2 failed, 2 total", 120);
        }

        return new ExecutionResult(0, "PASS  tests/GreetingTest\n  ✓ greets by name\n  ✓ falls back to World\n\nTests: 2 passed, 2 total", 140);
    }
}
