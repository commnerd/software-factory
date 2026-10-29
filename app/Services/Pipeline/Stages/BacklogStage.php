<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\GateType;
use App\Models\ImprovementProposal;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\PipelineOrchestrator;
use App\Services\Pipeline\StageResult;

/**
 * The human decision of every cycle: out of everything proposed, what do we build now?
 * Anything not chosen stays in the backlog (deferred) for a later cycle.
 */
class BacklogStage implements StageHandler
{
    public function __construct(private readonly GateService $gates) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $open = $project->proposals()->whereIn('status', ImprovementProposal::OPEN)->orderByDesc('score')->orderBy('id')->get();

        if ($open->isEmpty()) {
            // Nothing to choose from: close the cycle quietly instead of inventing work.
            app(PipelineOrchestrator::class)->skipCycle($project, 'the survey found nothing worth proposing');

            return StageResult::skipped();
        }

        $capacity = (int) config('factory.improvement.cycle_capacity');
        $recommended = $open->filter(fn ($p) => $p->impact >= 3)->take($capacity)->pluck('id')->all()
            ?: $open->take(1)->pluck('id')->all();

        $payload = [
            'recommended_ids' => $recommended,
            'capacity' => $capacity,
            'proposals' => $open->map(fn (ImprovementProposal $p) => [
                'id' => $p->id, 'title' => $p->title, 'category' => $p->category, 'summary' => $p->summary,
                'rationale' => $p->rationale, 'impact' => $p->impact, 'effort' => $p->effort, 'score' => $p->score,
                'source' => $p->source, 'status' => $p->status, 'cycle' => $p->cycle,
                'acceptance_criteria' => $p->acceptance_criteria ?? [],
            ])->values()->all(),
        ];

        $this->gates->open(
            GateType::BacklogSelection,
            "Choose what to build — {$project->name}, cycle {$project->cycle}",
            $project,
            "{$open->count()} candidate improvement(s). Tick what this cycle should build (suggested: top {$capacity} by value-for-effort). The rest stay in the backlog. Reject to ask for a fresh survey using your note as the focus.",
            payload: $payload,
        );

        $record->update(['output' => "{$open->count()} proposals awaiting selection."]);

        return StageResult::awaitingApproval();
    }
}
