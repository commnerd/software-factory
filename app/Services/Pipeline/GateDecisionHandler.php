<?php

namespace App\Services\Pipeline;

use App\Enums\GateType;
use App\Enums\SdlcStage;
use App\Enums\TddPhase;
use App\Models\ApprovalGate;
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
                ? $this->confirmAssessment($gate, $data)
                : $this->pipeline->rerunStage($project, SdlcStage::Assessment, $gate->decision_note),

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

    /** The reviewer accepted (or overrode) the starting point: skip what exists, resume from there. */
    private function confirmAssessment(ApprovalGate $gate, array $data): void
    {
        $project = $gate->project;
        $override = isset($data['start_stage']) ? SdlcStage::tryFrom($data['start_stage']) : null;

        if ($override === SdlcStage::Assessment) {
            $override = null;
        }

        $this->planner->apply($project, $override);
        $this->pipeline->resume($project);
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
            $project->tasks()->where('tdd_phase', TddPhase::Blocked->value)->update(['tdd_phase' => TddPhase::Pending->value, 'status' => 'pending', 'attempts' => 0]);
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
