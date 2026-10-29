<?php

namespace App\Services\Pipeline;

use App\Enums\GateType;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\FactoryException;
use App\Models\ApprovalGate;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\SelfProposal;

/** Human-in-the-loop decision gates. Execution pauses on `pending` gates. */
class GateService
{
    public function open(
        GateType $type,
        string $title,
        ?Project $project = null,
        ?string $summary = null,
        ?string $detail = null,
        array $payload = [],
        ?ProjectTask $task = null,
        ?SelfProposal $proposal = null,
    ): ApprovalGate {
        return ApprovalGate::create([
            'project_id' => $project?->id,
            'project_task_id' => $task?->id,
            'self_proposal_id' => $proposal?->id,
            'type' => $type,
            'title' => $title,
            'summary' => $summary,
            'detail' => $detail,
            'payload' => $payload ?: null,
        ]);
    }

    public function openCostOverride(Project $project, BudgetExceededException $e): ApprovalGate
    {
        $raise = (float) config('factory.budget_raise_usd');

        return $this->open(
            GateType::CostOverride,
            "Budget limit reached — {$project->name}",
            $project,
            $e->getMessage().' Approve to raise the limit and continue, or reject to pause.',
            payload: [
                'spent' => (float) $project->spent_usd,
                'limit' => (float) $project->budget_limit_usd,
                'suggested_new_limit' => round((float) $project->spent_usd + $raise, 2),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data  extra decision input (e.g. new_budget)
     */
    public function decide(ApprovalGate $gate, bool $approved, ?string $note = null, ?string $by = null, array $data = []): ApprovalGate
    {
        if (! $gate->isPending()) {
            throw new FactoryException('This gate has already been decided.');
        }

        $gate->update([
            'status' => $approved ? 'approved' : 'rejected',
            'decision_note' => $note,
            'decided_by' => $by,
            'decided_at' => now(),
        ]);

        app(GateDecisionHandler::class)->handle($gate->fresh(), $approved, $data);

        return $gate;
    }
}
