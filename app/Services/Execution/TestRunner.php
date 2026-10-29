<?php

namespace App\Services\Execution;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TestSuite;
use App\Services\Execution\Contracts\Executor;

class TestRunner
{
    public function __construct(
        private readonly Executor $executor,
        private readonly Workspace $workspace,
        private readonly TestResultParser $parser,
    ) {}

    /**
     * Install dependencies + run the project's test command in an isolated container
     * and record the outcome. $phase: red|green|refactor|qa.
     */
    public function run(Project $project, string $phase, ?ProjectTask $task = null): TestSuite
    {
        $profile = $project->profile();
        $command = "({$profile['install']}) && ({$profile['test']})";

        $result = $this->executor->run(new ExecutionRequest(
            workspace: $this->workspace->path($project),
            image: $profile['image'],
            command: $command,
            timeout: (int) config('factory.container.timeout'),
            phase: $phase,
        ));

        $counts = $this->parser->parse($result->output);

        $status = match (true) {
            $result->successful() && $counts['failed'] === 0 => 'passed',
            $counts['failed'] > 0 => 'failed',
            default => 'error', // non-zero exit but nothing parseable: install/compile failure
        };

        return TestSuite::create([
            'project_id' => $project->id,
            'project_task_id' => $task?->id,
            'phase' => $phase,
            'status' => $status,
            'command' => $command,
            'image' => $profile['image'],
            'exit_code' => $result->exitCode,
            'total' => $counts['total'],
            'passed' => $counts['passed'],
            'failed' => $counts['failed'],
            'duration_ms' => $result->durationMs,
            'output' => $this->tail($result->output),
        ]);
    }

    private function tail(string $output, int $max = 20000): string
    {
        return strlen($output) > $max ? "[…truncated…]\n".substr($output, -$max) : $output;
    }
}
