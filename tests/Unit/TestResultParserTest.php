<?php

namespace Tests\Unit;

use App\Services\Execution\TestResultParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TestResultParserTest extends TestCase
{
    #[DataProvider('samples')]
    public function test_it_parses_runner_output(string $output, array $expected): void
    {
        $this->assertSame($expected, (new TestResultParser)->parse($output));
    }

    public static function samples(): array
    {
        return [
            'phpunit ok' => ["OK (5 tests, 9 assertions)", ['total' => 5, 'passed' => 5, 'failed' => 0]],
            'phpunit failures' => ["Tests: 5, Assertions: 9, Failures: 2.", ['total' => 5, 'passed' => 3, 'failed' => 2]],
            'laravel artisan test' => ["  Tests:    1 failed, 2 passed (3 assertions)\n", ['total' => 3, 'passed' => 2, 'failed' => 1]],
            'jest' => ["Tests:       1 failed, 2 passed, 3 total\n", ['total' => 3, 'passed' => 2, 'failed' => 1]],
            'pytest' => ["=== 1 failed, 2 passed in 0.12s ===", ['total' => 3, 'passed' => 2, 'failed' => 1]],
            'pytest all green' => ["3 passed in 0.02s", ['total' => 3, 'passed' => 3, 'failed' => 0]],
            'maven' => ["Tests run: 4, Failures: 1, Errors: 0, Skipped: 0", ['total' => 4, 'passed' => 3, 'failed' => 1]],
            'dotnet' => ["Passed!  - Failed:     0, Passed:     3, Skipped:     0, Total:     3", ['total' => 3, 'passed' => 3, 'failed' => 0]],
            'cargo' => ["test result: ok. 3 passed; 0 failed; 0 ignored", ['total' => 3, 'passed' => 3, 'failed' => 0]],
            'rspec' => ["3 examples, 1 failure", ['total' => 3, 'passed' => 2, 'failed' => 1]],
            'go' => ["--- PASS: TestA\n--- FAIL: TestB\n", ['total' => 2, 'passed' => 1, 'failed' => 1]],
            'unparseable' => ["boom", ['total' => 0, 'passed' => 0, 'failed' => 0]],
        ];
    }
}
