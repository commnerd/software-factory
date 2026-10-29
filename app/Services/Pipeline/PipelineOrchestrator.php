<?php

namespace App\Services\Pipeline;

use App\Enums\SdlcStage;
use App\Exceptions\BudgetExceededException;
use App\Jobs\AdvancePipelineJob;
use App\Models\Project;
use App\Services\Execution\Workspace;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\Stages\ArchitectureStage;
use App\Services\Pipeline\Stages\DeploymentPrepStage;
use App\Services\Pipeline\Stages\ImplementationStage;
use App\Services\Pipeline\Stages\QaStage;
use App\Services\Pipeline\Stages\RequirementsStage;
use App\Services\Pipeline\Stages\TaskBreakdownStage;
use Throwable;

/**
 * SDLC state machine:
 *   Requirements -> Architecture -> Task breakdown -> TDD implementation -> QA -> Deployment prep
 * Stages run back-to-back until one asks for a human decision (gate) or fails.
 */
class PipelineOrchestrator
{
    private const HANDLERS = [
        'requirements' => RequirementsStage::class,
        'architecture' => ArchitectureStage::class,
        'task_breakdown' => TaskBreakdownStage::class,
        'tdd_implementation' => ImplementationStage::class,
        'qa' => QaStage::class,
        'deployment_prep' => DeploymentPrepStage::class,
    ];

    public function __construct(
        private readonly GateService $gates,
        private readonly Workspace $workspace,
    ) {}

    /** Queue the pipeline to run (used by the dashboard's Start / Resume buttons). */
    public function start(Project $project): void
    {
        $this->resume($project);
    }

    public function resume(Project $project): void
    {
        $project->update(['status' => 'running', 'last_error' => null]);
        $project->stage($project->current_stage)->update(['status' => 'pending']);

        AdvancePipelineJob::dispatch($project->id);
    }

    public function pause(Project $project, ?string $reason = null): void
    {
        $project->update(['status' => 'paused', 'last_error' => $reason]);
    }

    /** Run stages synchronously until the pipeline pauses, fails or completes. */
    public function advance(Project $project): void
    {
        while (true) {
            $project->refresh();

            if ($project->status !== 'running') {
                return;
            }

            $stage = $project->current_stage;
            $record = $project->stage($stage);
            $record->markRunning();

            try {
                $result = $this->handler($stage)->run($project, $record);
            } catch (BudgetExceededException $e) {
                $this->gates->openCostOverride($project, $e);
                $record->update(['status' => 'awaiting_approval']);
                $project->update(['status' => 'awaiting_approval']);

                return;
            } catch (Throwable $e) {
                report($e);
                $record->update(['status' => 'failed']);
                $project->update(['status' => 'failed', 'last_error' => $e->getMessage()]);

                return;
            }

            if (! $result->isCompleted()) {
                $record->update(['status' => 'awaiting_approval']);
                $project->update(['status' => 'awaiting_approval']);

                return;
            }

            // Moves current_stage forward (or marks the project completed after the last stage).
            $this->markStageDone($project, $stage);
        }
    }

    /** A gate approved a stage whose handler had paused: finish it and continue. */
    public function completeStage(Project $project, SdlcStage $stage): void
    {
        $this->markStageDone($project, $stage);

        if ($project->status !== 'completed') {
            $project->update(['status' => 'running']);
            AdvancePipelineJob::dispatch($project->id);
        }
    }

    /** A gate rejected a stage: regenerate it with the reviewer's feedback. */
    public function rerunStage(Project $project, SdlcStage $stage, ?string $feedback): void
    {
        $project->stage($stage)->update(['feedback' => $feedback, 'status' => 'pending']);
        $project->update(['current_stage' => $stage]);
        $this->resume($project);
    }

    /** Human kicked off deployment: commit the workspace and close the pipeline. */
    public function deploy(Project $project): void
    {
        $this->workspace->commit($project, "Release prepared by Software Factory ({$project->name})");

        $project->stage(SdlcStage::DeploymentPrep)->markCompleted();
        $project->update(['status' => 'completed', 'last_error' => null]);
    }

    private function markStageDone(Project $project, SdlcStage $stage): void
    {
        $project->stage($stage)->markCompleted();

        if ($next = $stage->next()) {
            $project->update(['current_stage' => $next]);
        } else {
            $project->update(['status' => 'completed']);
        }
    }

    private function handler(SdlcStage $stage): StageHandler
    {
        return app(self::HANDLERS[$stage->value]);
    }
}
