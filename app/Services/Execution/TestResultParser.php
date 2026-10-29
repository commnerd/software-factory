<?php

namespace App\Services\Execution;

/** Best-effort extraction of pass/fail counts from common test-runner output. */
class TestResultParser
{
    /** @return array{total: int, passed: int, failed: int} */
    public function parse(string $output): array
    {
        $o = preg_replace('/\e\[[0-9;]*m/', '', $output); // strip ANSI colours

        // Laravel / Pest: "Tests:    1 failed, 2 passed (3 assertions)"
        // Jest / Vitest: "Tests:  1 failed, 2 passed, 3 total"
        if (preg_match('/Tests:\s+(.*?)(?:\(\d+ assertions\)|\n|$)/', $o, $m) && preg_match('/\d+ (failed|passed)/', $m[1])) {
            $failed = $this->count($m[1], 'failed');
            $passed = $this->count($m[1], 'passed');

            return $this->result($passed + $failed + $this->count($m[1], 'skipped'), $passed, $failed);
        }

        // PHPUnit: "OK (5 tests, 9 assertions)" / "Tests: 5, Assertions: 9, Failures: 2, Errors: 1."
        if (preg_match('/OK \((\d+) tests?/', $o, $m)) {
            return $this->result((int) $m[1], (int) $m[1], 0);
        }
        if (preg_match('/Tests: (\d+), Assertions: \d+(.*)/', $o, $m)) {
            $failed = $this->count($m[2], 'Failures', ':') + $this->count($m[2], 'Errors', ':');

            return $this->result((int) $m[1], max(0, (int) $m[1] - $failed), $failed);
        }

        // Maven surefire: "Tests run: 5, Failures: 1, Errors: 0, Skipped: 0"
        if (preg_match_all('/Tests run: (\d+), Failures: (\d+), Errors: (\d+)/', $o, $m)) {
            $i = count($m[1]) - 1;
            $failed = (int) $m[2][$i] + (int) $m[3][$i];

            return $this->result((int) $m[1][$i], (int) $m[1][$i] - $failed, $failed);
        }

        // dotnet: "Passed!  - Failed:     0, Passed:     3, Skipped:     0, Total:     3"
        if (preg_match('/Failed:\s+(\d+), Passed:\s+(\d+), Skipped:\s+\d+, Total:\s+(\d+)/', $o, $m)) {
            return $this->result((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        // cargo: "test result: ok. 3 passed; 0 failed;"
        if (preg_match_all('/test result: \w+\. (\d+) passed; (\d+) failed/', $o, $m)) {
            $passed = array_sum($m[1]);
            $failed = array_sum($m[2]);

            return $this->result($passed + $failed, $passed, $failed);
        }

        // RSpec: "3 examples, 1 failure"
        if (preg_match('/(\d+) examples?, (\d+) failures?/', $o, $m)) {
            return $this->result((int) $m[1], (int) $m[1] - (int) $m[2], (int) $m[2]);
        }

        // Vitest: "Tests  1 failed | 2 passed (3)"
        if (preg_match('/^\s*Tests\s+((?:\d+ \w+(?: \| )?)+)/m', $o, $m) && preg_match('/\d+ (failed|passed)/', $m[1])) {
            $failed = $this->count($m[1], 'failed');
            $passed = $this->count($m[1], 'passed');

            return $this->result($passed + $failed, $passed, $failed);
        }

        // pytest: "1 failed, 2 passed in 0.12s"
        if (preg_match('/\d+ (?:passed|failed).* in [\d.]+s/', $o)) {
            $failed = $this->count($o, 'failed');
            $passed = $this->count($o, 'passed');

            return $this->result($passed + $failed, $passed, $failed);
        }

        // Go: count verbose results, else package lines
        $goPass = preg_match_all('/^--- PASS/m', $o);
        $goFail = preg_match_all('/^--- FAIL/m', $o);
        if ($goPass + $goFail > 0) {
            return $this->result($goPass + $goFail, $goPass, $goFail);
        }
        $pkgOk = preg_match_all('/^ok\s/m', $o);
        $pkgFail = preg_match_all('/^FAIL\s/m', $o);
        if ($pkgOk + $pkgFail > 0) {
            return $this->result($pkgOk + $pkgFail, $pkgOk, $pkgFail);
        }

        return $this->result(0, 0, 0);
    }

    private function count(string $haystack, string $label, string $sep = ' '): int
    {
        $pattern = $sep === ':' ? "/{$label}: (\d+)/" : "/(\d+) {$label}/";

        return preg_match($pattern, $haystack, $m) ? (int) $m[1] : 0;
    }

    /** @return array{total: int, passed: int, failed: int} */
    private function result(int $total, int $passed, int $failed): array
    {
        return ['total' => $total, 'passed' => $passed, 'failed' => $failed];
    }
}
