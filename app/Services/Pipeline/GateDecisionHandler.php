<?php

namespace App\Services\Pipeline;

use App\Enums\GateType;
use App\Enums\SdlcStage;
use App\Enums\TddPhase;
use App\Models\ApprovalGate;
use App\Models\ImprovementProposal;
use App\Services\SelfIteration\SelfIterationService;

/** Turns a human decision into the next pipeline action. */
class GateDecisionHandler
{
    public function __construct(
        private readonly PipelineOrchestrator $pipeline,
        private readonly SelfIterationService $self,
        private readonly AssessmentPlanner $planner,
    ) {}

    public function handle(ApprovalGate $gate, bool $approved, array $data = []): void
    {
        $project = $gate->project;

        match ($gate->type) {
            GateType::AssessmentReview => $approved
                ? $this->confirmAssessment($gate)
                : $this->pipeline->rerunStage($project, SdlcStage::Assessment, $gate->decision_note),

            GateType::BacklogSelection => $approved
                ? $this->selectBacklog($gate, $data)
                : $this->pipeline->rerunStage($project, SdlcStage::Survey, $gate->decision_note),

            GateType::ArchitectureApproval => $approved
                ? $this->pipeline->completeStage($project, SdlcStage::Architecture)
                : $this->pipeline->rerunStage($project, SdlcStage::Architecture, $gate->decision_note),

            GateType::CostOverride => $approved
                ? $this->raiseBudgetAndResume($gate, $data)
                : $this->pipeline->pause($project, 'Paused: budget override rejected.'),

            GateType::Deployment => $approved
                ? $this->pipeline->deploy($project)
                : $this->pipeline->rerunStage($project, SdlcStage::DeploymentPrep, $gate->decision_note),

            GateType::SelfModification => $approved
                ? $this->self->stage($gate->selfProposal)
                : $this->self->reject($gate->selfProposal),

            GateType::Intervention => $this->intervention($gate, $approved),
        };
    }

    /** Assessment confirmed: seed cycle 1's backlog from the findings and start improving. */
    private function confirmAssessment(ApprovalGate $gate): void
    {
        $project = $gate->project;

        $this->planner->apply($project);
        $this->pipeline->enterImprovement($project);
        $this->pipeline->resume($project);
    }

    /**
     * The cycle's human decision. Chosen proposals are approved for building; the rest of the open
     * backlog is deferred (kept, not lost). Choosing nothing closes the cycle without building.
     */
    private function selectBacklog(ApprovalGate $gate, array $data): void
    {
        $project = $gate->project;
        $open = $project->proposals()->whereIn('status', ImprovementProposal::OPEN)->pluck('id')->all();
        $chosen = array_values(array_intersect($open, array_map('intval', (array) ($data['selected_ids'] ?? []))));

        $project->proposals()->whereIn('id', $chosen)->update(['status' => 'approved', 'decision_note' => $gate->decision_note]);
        $project->proposals()->whereIn('id', array_diff($open, $chosen))->update(['status' => 'deferred']);

        if ($chosen === []) {
            $this->pipeline->skipCycle($project, 'no proposals were selected');

            return;
        }

        $this->pipeline->completeStage($project, SdlcStage::Backlog);
    }

    private function raiseBudgetAndResume(ApprovalGate $gate, array $data): void
    {
        $project = $gate->project;
        $newLimit = (float) ($data['new_budget'] ?? $gate->payload['suggested_new_limit'] ?? ($project->spent_usd + config('factory.budget_raise_usd')));

        $project->update(['budget_limit_usd' => max($newLimit, (float) $project->spent_usd + 0.01)]);
        $this->pipeline->resume($project);
    }

    private function intervention(ApprovalGate $gate, bool $approved): void
    {
        $project = $gate->project;
        $kind = $gate->payload['kind'] ?? 'tdd_stuck';

        if ($kind === 'qa_failed') {
            if (! $approved) {
                $this->pipeline->pause($project, 'Paused: QA failures were not approved for another TDD pass.');

                return;
            }

            // Send blocked/unfinished work back through TDD.
            $project->cycleTasks()->where('tdd_phase', TddPhase::Blocked->value)->update(['tdd_phase' => TddPhase::Pending->value, 'status' => 'pending', 'attempts' => 0]);
            $project->update(['current_stage' => SdlcStage::TddImplementation]);
            $this->pipeline->resume($project);

            return;
        }

        // tdd_stuck: approve = retry the task, reject = skip (block) it and continue.
        $task = $gate->task;
        if ($task) {
            if ($approved) {
                $task->update(['attempts' => 0]);
                $task->setPhase(TddPhase::Pending);
            } else {
                $task->setPhase(TddPhase::Blocked);
                $task->update(['notes' => trim(($task->notes ?? '')."\nSkipped by reviewer: ".($gate->decision_note ?? 'no reason given'))]);
            }
        }

        $this->pipeline->resume($project);
    }
}
