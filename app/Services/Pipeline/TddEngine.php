<?php

namespace App\Services\Pipeline;

use App\Enums\FactoryStep;
use App\Enums\SdlcStage;
use App\Enums\TddPhase;
use App\Exceptions\FactoryException;
use App\Exceptions\TddStuckException;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectArtifact;
use App\Models\ProjectTask;
use App\Models\TestSuite;
use App\Services\Ai\AgentRunner;
use App\Services\Execution\TestRunner;
use App\Services\Execution\Workspace;
use App\Services\Pipeline\Stages\ProjectContext;

/**
 * Enforces strict TDD for one task:
 *   RED      tests are written first and MUST fail (otherwise they prove nothing)
 *   GREEN    implementation iterates until the suite passes; tests are frozen
 *   REFACTOR cleanup must keep 100% green or it is reverted
 */
class TddEngine
{
    public function __construct(
        private readonly AgentRunner $agents,
        private readonly TestRunner $tests,
        private readonly Workspace $workspace,
    ) {}

    public function implement(Project $project, ProjectTask $task): void
    {
        $this->workspace->initGit($project);

        if ($task->type === 'characterization') {
            $this->characterize($project, $task);
            $task->setPhase(TddPhase::Done);

            return;
        }

        $testPaths = $this->red($project, $task);
        $this->green($project, $task, $testPaths);
        $this->refactor($project, $task, $testPaths);

        $task->setPhase(TddPhase::Done);
    }

    /**
     * Existing, untested behaviour (imported repos): there is nothing to turn red. The tests must
     * document what the code already does, so they have to PASS against the current implementation;
     * once they do they protect that behaviour from the refactors and features that follow.
     */
    private function characterize(Project $project, ProjectTask $task): void
    {
        $task->setPhase(TddPhase::Green);
        $feedback = '';

        for ($attempt = 1; $attempt <= (int) config('factory.tdd.max_red_attempts'); $attempt++) {
            $run = $this->agents->run(FactoryStep::TddRed, $this->vars($project, $task) + ['feedback' => $feedback], $project, $task);
            $this->workspace->write($project, $this->files($run), $task);
            $suite = $this->tests->run($project, 'characterize', $task);

            if ($this->passes($suite)) {
                return;
            }

            $feedback = "These characterization tests FAIL against the existing code. They must describe behaviour the code already has, not behaviour you wish it had. Output:\n".mb_substr((string) $suite->output, -3000);
        }

        throw new TddStuckException($task, 'Characterization tests do not pass against the existing code — either the behaviour is unclear or it is a real bug that needs a human decision.');
    }

    /** @return list<string> paths of the tests written (frozen from here on) */
    private function red(Project $project, ProjectTask $task): array
    {
        $task->setPhase(TddPhase::Red);
        $feedback = '';

        for ($attempt = 1; $attempt <= (int) config('factory.tdd.max_red_attempts'); $attempt++) {
            $run = $this->agents->run(FactoryStep::TddRed, $this->vars($project, $task) + ['feedback' => $feedback], $project, $task);
            $paths = $this->workspace->write($project, $this->files($run), $task);
            $suite = $this->tests->run($project, 'red', $task);

            if (! $suite->isGreen()) {
                return $paths; // failing before implementation exists is exactly what we want
            }

            $feedback = 'The tests PASSED before any implementation existed, so they do not test the required behaviour. Rewrite them so they fail until the feature is implemented.';
        }

        throw new TddStuckException($task, 'Could not produce a failing test (red phase) — the generated tests pass without an implementation.');
    }

    private function green(Project $project, ProjectTask $task, array $testPaths): void
    {
        $task->setPhase(TddPhase::Green);
        $failure = (string) $task->testSuites()->where('phase', 'red')->latest('id')->value('output');
        $max = (int) config('factory.tdd.max_green_attempts');

        for ($attempt = 1; $attempt <= $max; $attempt++) {
            $task->increment('attempts');

            $run = $this->agents->run(FactoryStep::TddGreen, $this->vars($project, $task) + [
                'test_output' => $failure,
                'attempt' => $attempt,
                'max_attempts' => $max,
            ], $project, $task);

            // Tests are frozen: implementation may not "pass" by editing the tests.
            $files = array_values(array_filter($this->files($run), fn ($f) => ! in_array($f['path'], $testPaths, true)));
            $this->workspace->write($project, $files, $task);

            $suite = $this->tests->run($project, 'green', $task);

            if ($this->passes($suite)) {
                return;
            }

            $failure = (string) $suite->output;
        }

        throw new TddStuckException($task, "Tests still failing after {$max} implementation attempts.");
    }

    private function refactor(Project $project, ProjectTask $task, array $testPaths): void
    {
        $task->setPhase(TddPhase::Refactor);

        $run = $this->agents->run(FactoryStep::TddRefactor, $this->vars($project, $task), $project, $task);
        $files = array_values(array_filter($this->files($run), fn ($f) => ! in_array($f['path'], $testPaths, true)));

        if ($files === []) {
            return;
        }

        $snapshot = $this->workspace->snapshot($project, array_column($files, 'path'));
        $this->workspace->write($project, $files, $task);
        $suite = $this->tests->run($project, 'refactor', $task);

        if (! $this->passes($suite)) {
            $this->workspace->restore($project, $snapshot);
            $task->update(['notes' => trim(($task->notes ?? '')."\nRefactor reverted: it broke the suite.")]);
        }
    }

    private function passes(TestSuite $suite): bool
    {
        return $suite->isGreen() && $suite->total > 0;
    }

    /** @return list<array{path: string, content: string}> */
    private function files(AgentRun $run): array
    {
        $files = $run->json()['files'] ?? null;

        if (! is_array($files) || $files === []) {
            throw new FactoryException("Step {$run->step} did not return any files (expected JSON {\"files\":[{\"path\",\"content\"}]}).");
        }

        return array_values(array_filter($files, fn ($f) => is_array($f) && isset($f['path'], $f['content'])));
    }

    private function vars(Project $project, ProjectTask $task): array
    {
        return [
            'project' => ProjectContext::basic($project),
            'architecture' => ProjectContext::stageOutput($project, SdlcStage::Architecture),
            'task' => [
                'title' => $task->title,
                'description' => $task->description,
                'acceptance_criteria' => $task->acceptance_criteria ?? [],
                'suggested_files' => $task->files ?? [],
            ],
            'mode' => $task->type === 'characterization' ? 'characterization' : 'feature',
            'existing_files' => ProjectContext::files($project, hints: [
                ...($task->files ?? []),
                ...preg_split('/\W+/', strtolower($task->title), -1, PREG_SPLIT_NO_EMPTY),
            ]),
            'test_files' => ProjectArtifact::where('project_id', $project->id)->where('project_task_id', $task->id)->where('kind', 'test')->pluck('content', 'path')->all(),
        ];
    }
}
