<?php

namespace App\Services\SelfIteration;

use App\Enums\FactoryStep;
use App\Enums\GateType;
use App\Exceptions\FactoryException;
use App\Models\SelfProposal;
use App\Models\TestSuite;
use App\Services\Ai\AgentRunner;
use App\Services\Execution\Contracts\Executor;
use App\Services\Execution\ExecutionRequest;
use App\Services\Execution\TestResultParser;
use App\Services\Pipeline\GateService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Meta-programming loop: the factory reads its own source, proposes improvements,
 * implements them (tests included) in an isolated copy, runs the suite in a container,
 * asks a human, and only then STAGES a patch. Nothing is ever applied automatically.
 */
class SelfIterationService
{
    private const COPY_DIRS = ['app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests'];

    private const COPY_FILES = ['artisan', 'composer.json', 'composer.lock', 'phpunit.xml', 'package.json', 'vite.config.js', 'README.md'];

    public function __construct(
        private readonly AgentRunner $agents,
        private readonly Executor $executor,
        private readonly TestResultParser $parser,
        private readonly GateService $gates,
    ) {}

    /** Ask the analyst skill for improvement proposals based on the current codebase. @return list<SelfProposal> */
    public function analyze(?string $focus = null): array
    {
        $run = $this->agents->run(FactoryStep::SelfAnalysis, [
            'focus' => $focus ?: 'any high-value improvement',
            'codebase' => $this->inspectCodebase(),
        ]);

        $proposals = [];
        foreach ($run->json()['proposals'] ?? [] as $p) {
            if (empty($p['title'])) {
                continue;
            }
            $proposals[] = SelfProposal::create([
                'title' => $p['title'],
                'rationale' => $p['rationale'] ?? null,
                'plan' => $p['plan'] ?? null,
            ]);
        }

        if ($proposals === []) {
            throw new FactoryException('The analysis step returned no usable proposals.');
        }

        return $proposals;
    }

    /** implement -> test in staging -> open gate. Safe to run from a queue job. */
    public function process(SelfProposal $proposal): void
    {
        try {
            $this->implement($proposal);
            $this->test($proposal);
        } catch (\Throwable $e) {
            report($e);
            $proposal->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
        }
    }

    public function implement(SelfProposal $proposal): void
    {
        $proposal->update(['status' => 'implementing', 'last_error' => null]);

        $run = $this->agents->run(FactoryStep::SelfImplementation, [
            'proposal' => ['title' => $proposal->title, 'rationale' => $proposal->rationale, 'plan' => $proposal->plan],
            'relevant_files' => $this->relevantFiles($proposal),
        ]);

        $files = $run->json()['files'] ?? [];
        if (! is_array($files) || $files === []) {
            throw new FactoryException('Implementation step returned no files.');
        }

        $files = array_values(array_filter($files, fn ($f) => is_array($f) && isset($f['path'], $f['content'])));
        foreach ($files as $f) {
            $this->assertAllowed($f['path']);
        }

        // TDD policy applies to the factory too.
        $touchesCode = collect($files)->contains(fn ($f) => ! str_starts_with($f['path'], 'tests/') && str_ends_with($f['path'], '.php'));
        $hasTests = collect($files)->contains(fn ($f) => str_starts_with($f['path'], 'tests/'));
        if ($touchesCode && ! $hasTests) {
            throw new FactoryException('Rejected: the proposal changes PHP code but ships no tests (TDD policy).');
        }

        $proposal->update(['changes' => $files]);
    }

    public function test(SelfProposal $proposal): TestSuite
    {
        $proposal->update(['status' => 'testing']);

        $staging = $this->prepareStaging($proposal);
        $command = (string) config('factory.self.test_command');

        $result = $this->executor->run(new ExecutionRequest(
            workspace: $staging,
            image: (string) config('factory.self.image'),
            command: $command,
            timeout: (int) config('factory.container.timeout'),
            env: ['APP_KEY' => (string) config('app.key'), 'APP_ENV' => 'testing'],
            mounts: [base_path('vendor') => '/workspace/vendor'],
            phase: 'self',
        ));

        $counts = $this->parser->parse($result->output);
        $passed = $result->successful() && $counts['failed'] === 0;

        $suite = TestSuite::create([
            'self_proposal_id' => $proposal->id,
            'phase' => 'self',
            'status' => $passed ? 'passed' : ($counts['failed'] > 0 ? 'failed' : 'error'),
            'command' => $command,
            'image' => config('factory.self.image'),
            'exit_code' => $result->exitCode,
            'total' => $counts['total'],
            'passed' => $counts['passed'],
            'failed' => $counts['failed'],
            'duration_ms' => $result->durationMs,
            'output' => substr($result->output, -20000),
        ]);

        $proposal->update([
            'test_status' => $passed ? 'passed' : 'failed',
            'test_output' => $suite->output,
            'status' => $passed ? 'awaiting_approval' : 'failed',
        ]);

        if ($passed) {
            $this->gates->open(
                GateType::SelfModification,
                "Stage self-modification — {$proposal->title}",
                summary: "The change passes the full test suite in an isolated container ({$suite->passed}/{$suite->total}). Approving STAGES a patch for you to apply; it does not modify the running factory.",
                detail: $this->buildPatch($proposal, $staging),
                payload: ['files' => array_column($proposal->changes, 'path')],
                proposal: $proposal,
            );
        }

        return $suite;
    }

    /** Approved: write the patch bundle to the staging area. */
    public function stage(SelfProposal $proposal): void
    {
        $dir = rtrim(config('factory.self.staged_path'), '/')."/{$proposal->id}";
        File::deleteDirectory($dir);
        File::ensureDirectoryExists("{$dir}/files");

        foreach ($proposal->changes as $change) {
            File::ensureDirectoryExists(dirname("{$dir}/files/{$change['path']}"));
            File::put("{$dir}/files/{$change['path']}", $change['content']);
        }

        File::put("{$dir}/changes.patch", $this->buildPatch($proposal, $this->stagingPath($proposal)));
        $proposal->update(['status' => 'staged', 'staged_path' => $dir]);
    }

    public function reject(SelfProposal $proposal): void
    {
        $proposal->update(['status' => 'rejected']);
    }

    /** Apply a staged patch to the live checkout (CLI only: `php artisan factory:apply-staged`). */
    public function applyStaged(SelfProposal $proposal): void
    {
        if ($proposal->status !== 'staged' || ! $proposal->staged_path) {
            throw new FactoryException('Proposal is not staged.');
        }

        $process = new Process(['git', 'apply', '--whitespace=nowarn', "{$proposal->staged_path}/changes.patch"], base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            throw new FactoryException('git apply failed: '.$process->getErrorOutput());
        }
    }

    public function assertAllowed(string $path): void
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || preg_match('#(^|/)\.\.(/|$)#', $path)) {
            throw new FactoryException("Unsafe path '{$path}'.");
        }

        foreach (config('factory.self.denied_patterns') as $denied) {
            if ($path === rtrim($denied, '/') || str_starts_with($path, $denied) || str_contains($path, '/'.$denied)) {
                throw new FactoryException("Path '{$path}' may not be modified by self-iteration.");
            }
        }

        $top = explode('/', $path)[0];
        if (! in_array($top, config('factory.self.allowed_paths'), true)) {
            throw new FactoryException("Path '{$path}' is outside the allowed self-modification scope.");
        }
    }

    // --------------------------------------------------------------------- internals

    private function stagingPath(SelfProposal $proposal): string
    {
        return rtrim(config('factory.self.staging_path'), '/')."/{$proposal->id}";
    }

    private function prepareStaging(SelfProposal $proposal): string
    {
        $dir = $this->stagingPath($proposal);
        File::deleteDirectory($dir);
        File::ensureDirectoryExists($dir);

        foreach (self::COPY_DIRS as $d) {
            if (! is_dir(base_path($d))) {
                continue;
            }
            foreach (Finder::create()->files()->ignoreDotFiles(false)->in(base_path($d))->notName('*.sqlite')->notPath('#^cache/#') as $file) {
                $target = "{$dir}/{$d}/".$file->getRelativePathname();
                File::ensureDirectoryExists(dirname($target));
                copy($file->getPathname(), $target);
            }
        }

        foreach (self::COPY_FILES as $f) {
            if (is_file(base_path($f))) {
                copy(base_path($f), "{$dir}/{$f}");
            }
        }

        // The container needs these to boot; vendor/ is bind-mounted read-only at run time.
        File::ensureDirectoryExists("{$dir}/storage/framework/views");
        File::ensureDirectoryExists("{$dir}/storage/framework/cache");
        File::ensureDirectoryExists("{$dir}/storage/framework/sessions");
        File::ensureDirectoryExists("{$dir}/storage/logs");
        File::ensureDirectoryExists("{$dir}/bootstrap/cache");

        foreach ($proposal->changes ?? [] as $change) {
            $this->assertAllowed($change['path']);
            File::ensureDirectoryExists(dirname("{$dir}/{$change['path']}"));
            File::put("{$dir}/{$change['path']}", $change['content']);
        }

        return $dir;
    }

    private function buildPatch(SelfProposal $proposal, string $staging): string
    {
        $patch = '';

        foreach ($proposal->changes ?? [] as $change) {
            $live = base_path($change['path']);
            $new = "{$staging}/{$change['path']}";
            $exists = is_file($live);

            $process = new Process([
                'diff', '-u',
                '--label', $exists ? "a/{$change['path']}" : '/dev/null',
                '--label', "b/{$change['path']}",
                $exists ? $live : '/dev/null',
                $new,
            ]);
            $process->run();
            $patch .= $process->getOutput();
        }

        return $patch === '' ? '(no textual differences)' : $patch;
    }

    private function relevantFiles(SelfProposal $proposal): array
    {
        preg_match_all('#[\w./-]+\.(?:php|vue|js|md)#', "{$proposal->plan} {$proposal->title} {$proposal->rationale}", $m);
        $out = [];
        $bytes = 0;

        foreach (array_unique($m[0]) as $path) {
            try {
                $this->assertAllowed($path);
            } catch (FactoryException) {
                continue;
            }
            if (is_file(base_path($path)) && $bytes < 60_000 && count($out) < 10) {
                $content = File::get(base_path($path));
                $bytes += strlen($content);
                $out[$path] = $content;
            }
        }

        return $out;
    }

    /** A compact, bounded view of the codebase for the analyst. */
    private function inspectCodebase(): array
    {
        $tree = [];
        $todos = [];

        foreach (['app', 'resources/js', 'routes', 'tests', 'database/migrations', 'config'] as $dir) {
            if (! is_dir(base_path($dir))) {
                continue;
            }
            foreach (Finder::create()->files()->in(base_path($dir))->name(['*.php', '*.vue', '*.js']) as $file) {
                $rel = $dir.'/'.$file->getRelativePathname();
                $lines = substr_count((string) file_get_contents($file->getPathname()), "\n") + 1;
                $tree[] = "{$rel} ({$lines} lines)";

                if (count($todos) < 25 && preg_match_all('/(TODO|FIXME)[^\n]*/', (string) file_get_contents($file->getPathname()), $t)) {
                    foreach ($t[0] as $line) {
                        $todos[] = "{$rel}: {$line}";
                    }
                }
            }
        }

        return [
            'files' => array_slice($tree, 0, 300),
            'todos' => $todos,
            'recent_agent_errors' => \App\Models\AgentRun::where('status', 'failed')->latest()->limit(5)->pluck('error')->all(),
            'last_self_test' => TestSuite::where('phase', 'self')->latest()->value('status'),
        ];
    }
}
