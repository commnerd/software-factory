<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\GateType;
use App\Enums\SdlcStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Execution\TestRunner;
use App\Services\Import\RepositoryImporter;
use App\Services\Pipeline\AssessmentPlanner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\StageResult;
use Throwable;

/**
 * First stage of every project. Greenfield (no repository content): a no-op.
 * Existing code: import it, measure it, have the AI judge where the project stands,
 * then ask a human to confirm the starting point before anything is (re)built.
 */
class AssessmentStage implements StageHandler
{
    public function __construct(
        private readonly RepositoryImporter $importer,
        private readonly TestRunner $tests,
        private readonly AgentRunner $agents,
        private readonly AssessmentPlanner $planner,
        private readonly GateService $gates,
    ) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $inventory = $this->importer->import($project);

        if ($inventory === null) {
            $project->update(['existing_code' => false, 'assessment' => null]);
            $record->update(['output' => 'Greenfield project: no existing repository content, so the factory starts from requirements.']);

            return StageResult::completed();
        }

        $project->refresh();
        $baseline = $this->baseline($project);

        $run = $this->agents->run(FactoryStep::ProjectAssessment, [
            'project' => ProjectContext::basic($project),
            'requested_language' => $project->language,
            'inventory' => $inventory,
            'baseline' => $baseline ? [
                'status' => $baseline->status, 'passed' => $baseline->passed, 'total' => $baseline->total,
                'output_tail' => mb_substr((string) $baseline->output, -3000),
            ] : 'not run',
            'brief' => $project->brief ?? '',
            'feedback' => $record->feedback ?? '',
        ], $project);

        $plan = $this->planner->normalize($run->json(), $inventory, $baseline);
        $project->update(['assessment' => $plan + ['inventory' => $this->trimInventory($inventory)], 'existing_code' => true]);

        $summary = $this->planner->summaryMarkdown($plan, $inventory);
        $record->update(['output' => $summary, 'feedback' => null]);

        $recommended = $this->planner->startStage($plan);

        $this->gates->open(
            GateType::AssessmentReview,
            "Confirm starting point — {$project->name}",
            $project,
            "The repository already contains work. Recommended: resume at \"{$recommended->label()}\". Pick a different stage to override, or reject with a note to re-assess.",
            detail: $summary,
            payload: [
                'recommended_stage' => $recommended->value,
                'stages' => collect(SdlcStage::cases())->reject(fn ($s) => $s === SdlcStage::Assessment)
                    ->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->values()->all(),
                'agent_run_id' => $run->id,
            ],
        );

        return StageResult::awaitingApproval();
    }

    private function baseline(Project $project)
    {
        if (! config('factory.import.run_baseline')) {
            return null;
        }

        try {
            return $this->tests->run($project, 'baseline');
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Keep the stored copy small: the full file list lives in the workspace anyway. */
    private function trimInventory(array $inventory): array
    {
        unset($inventory['readme_excerpt'], $inventory['doc_excerpts']);

        return $inventory;
    }
}
