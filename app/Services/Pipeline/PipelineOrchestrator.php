<?php

namespace App\Services\Pipeline;

use App\Enums\Lifecycle;
use App\Enums\SdlcStage;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\FactoryException;
use App\Jobs\AdvancePipelineJob;
use App\Models\ImprovementProposal;
use App\Models\Project;
use App\Services\Execution\Workspace;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\Stages\ArchitectureStage;
use App\Services\Pipeline\Stages\AssessmentStage;
use App\Services\Pipeline\Stages\BacklogStage;
use App\Services\Pipeline\Stages\DeploymentPrepStage;
use App\Services\Pipeline\Stages\ImplementationStage;
use App\Services\Pipeline\Stages\QaStage;
use App\Services\Pipeline\Stages\RequirementsStage;
use App\Services\Pipeline\Stages\RetrospectiveStage;
use App\Services\Pipeline\Stages\SurveyStage;
use App\Services\Pipeline\Stages\TaskBreakdownStage;
use Throwable;

/**
 * Two lifecycles share one state machine (see SdlcStage::sequence):
 *
 *   build    Assessment → Requirements → Architecture → Task breakdown → TDD → QA → Deployment prep
 *            └─ after the first release the project switches to ─┐
 *   improve  Survey → Backlog review → Cycle planning → TDD → QA → Release prep → Retrospective
 *            └─ then idles until the next cycle is started (by a human, or by the schedule) ─┘
 *
 * Stages run back-to-back until one asks for a human decision (gate) or fails.
 */
class PipelineOrchestrator
{
    private const HANDLERS = [
        'assessment' => AssessmentStage::class,
        'requirements' => RequirementsStage::class,
        'architecture' => ArchitectureStage::class,
        'task_breakdown' => TaskBreakdownStage::class,
        'tdd_implementation' => ImplementationStage::class,
        'qa' => QaStage::class,
        'deployment_prep' => DeploymentPrepStage::class,
        'survey' => SurveyStage::class,
        'backlog' => BacklogStage::class,
        'retrospective' => RetrospectiveStage::class,
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

    /**
     * Begin the next continuous-improvement cycle: survey the code again, propose, choose, build, learn.
     * Only valid when the project is idle (released, nothing in flight).
     */
    public function startCycle(Project $project): void
    {
        if ($project->status !== 'idle') {
            throw new FactoryException('A new cycle can only start when the project is idle (the previous cycle or build has finished).');
        }

        $project->update([
            'lifecycle' => Lifecycle::Improve,
            'cycle' => $project->cycle + 1,
            'current_stage' => SdlcStage::Survey,
            'cycle_started_at' => now(),
        ]);

        $this->resume($project);
    }

    /** Assessment confirmed for an imported repository: skip the build lifecycle, start improving. */
    public function enterImprovement(Project $project): void
    {
        $project->update([
            'lifecycle' => Lifecycle::Improve,
            'cycle' => 1,
            'current_stage' => SdlcStage::Survey,
            'cycle_started_at' => now(),
        ]);
    }

    /** Run stages synchronously until the pipeline pauses, fails or goes idle. */
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

            if ($result->isSkipped()) {
                return; // the stage already closed the run (project is idle)
            }

            if (! $result->isCompleted()) {
                $record->update(['status' => 'awaiting_approval']);
                $project->update(['status' => 'awaiting_approval']);

                return;
            }

            // Moves current_stage forward, or ends the run (project goes idle) after the last stage.
            $this->markStageDone($project, $stage);
        }
    }

    /** A gate approved a stage whose handler had paused: finish it and continue. */
    public function completeStage(Project $project, SdlcStage $stage): void
    {
        $this->markStageDone($project, $stage);

        if ($project->status !== 'idle') {
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

    /** Nothing was chosen for this cycle: close it without building. */
    public function skipCycle(Project $project, string $reason): void
    {
        foreach ([SdlcStage::Backlog, SdlcStage::Survey] as $stage) {
            $project->stage($stage)->markCompleted($project->stage($stage)->output);
        }
        $project->stage(SdlcStage::Retrospective)->markCompleted("Cycle {$project->cycle} skipped: {$reason}");

        $this->goIdle($project);
    }

    /**
     * Human kicked off the release: commit the workspace and continue.
     * Improvement cycles commit to their own branch (factory/cycle-N) so the main line is never touched.
     */
    public function deploy(Project $project): void
    {
        if ($project->lifecycle === Lifecycle::Improve) {
            $titles = $project->proposals()->where('status', 'in_progress')->pluck('title')->all();
            $this->workspace->commit(
                $project,
                "Cycle {$project->cycle}: ".($titles ? implode('; ', $titles) : 'improvements')." (Software Factory)",
                "factory/cycle-{$project->cycle}",
            );

            // Shipped: what was built is done; anything with skipped/blocked work is marked failed (and can be revived).
            foreach ($project->proposals()->where('status', 'in_progress')->get() as $proposal) {
                $blocked = $proposal->tasks()->where('cycle', $project->cycle)->where('tdd_phase', 'blocked')->exists();

                $proposal->update($blocked
                    ? ['status' => 'failed', 'outcome' => 'Some of its tasks were blocked or skipped, so it did not ship in full.']
                    : ['status' => 'done', 'built_in_cycle' => $project->cycle, 'outcome' => "Shipped in cycle {$project->cycle}."]);
            }
        } else {
            $this->workspace->commit($project, "Release prepared by Software Factory ({$project->name})");
        }

        $this->completeStage($project, SdlcStage::DeploymentPrep);
    }

    private function markStageDone(Project $project, SdlcStage $stage): void
    {
        $project->stage($stage)->markCompleted();

        if ($next = $stage->next($project->lifecycle)) {
            $project->update(['current_stage' => $next]);
        } else {
            $this->goIdle($project);
        }
    }

    /** End of a build or of an improvement cycle: park the project until the next cycle starts. */
    private function goIdle(Project $project): void
    {
        // (A finished build stays "build/cycle 0" until startCycle() graduates it to continuous improvement.)
        $project->update([
            'status' => 'idle',
            'last_error' => null,
            'current_stage' => SdlcStage::Survey,
            'last_cycle_completed_at' => now(),
        ]);

        // Work that never shipped goes back on the table.
        $project->proposals()->whereIn('status', ['approved', 'in_progress'])->update(['status' => 'deferred']);
    }

    private function handler(SdlcStage $stage): StageHandler
    {
        return app(self::HANDLERS[$stage->value]);
    }
}
