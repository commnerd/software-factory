<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Models\AgentRun;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\StageResult;

/** Closes a cycle: what shipped, what didn't, what it cost, what to look at next time. */
class RetrospectiveStage implements StageHandler
{
    public function __construct(private readonly AgentRunner $agents) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $since = $project->cycle_started_at ?? $project->created_at;

        $shipped = $project->proposals()->where('built_in_cycle', $project->cycle)->get(['id', 'title', 'category', 'outcome']);
        $notShipped = $project->proposals()->where('status', 'failed')->where('updated_at', '>=', $since)->get(['id', 'title', 'category', 'outcome']);

        $suites = $project->testSuites()->where('created_at', '>=', $since)->get(['phase', 'status']);
        $metrics = [
            'cycle' => $project->cycle,
            'shipped' => $shipped->count(),
            'not_shipped' => $notShipped->count(),
            'test_runs' => $suites->count(),
            'failed_test_runs' => $suites->where('status', '!=', 'passed')->count(),
            'blocked_tasks' => $project->cycleTasks()->where('tdd_phase', 'blocked')->count(),
            'cost_usd' => round((float) AgentRun::where('project_id', $project->id)->where('is_test', false)->where('created_at', '>=', $since)->sum('cost_usd'), 4),
            'human_rejections' => $project->gates()->where('status', 'rejected')->where('decided_at', '>=', $since)->count(),
        ];

        $run = $this->agents->run(FactoryStep::CycleRetrospective, [
            'project' => ProjectContext::basic($project),
            'metrics' => $metrics,
            'shipped' => $shipped->all(),
            'not_shipped' => $notShipped->all(),
            'task_notes' => $project->cycleTasks()->whereNotNull('notes')->pluck('notes', 'title')->all(),
            'rejections' => $project->gates()->where('status', 'rejected')->where('decided_at', '>=', $since)->pluck('decision_note', 'title')->filter()->all(),
        ], $project);

        $header = sprintf(
            "**Cycle %d:** %d shipped · %d not shipped · %d blocked task(s) · $%s spent\n\n",
            $project->cycle, $metrics['shipped'], $metrics['not_shipped'], $metrics['blocked_tasks'], number_format($metrics['cost_usd'], 4),
        );

        $record->update(['output' => $header.$run->response]);

        return StageResult::completed();
    }
}
