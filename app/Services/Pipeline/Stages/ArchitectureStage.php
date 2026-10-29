<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\GateType;
use App\Enums\SdlcStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\StageResult;

/** Generates the design, then blocks on a human architecture approval. */
class ArchitectureStage implements StageHandler
{
    public function __construct(
        private readonly AgentRunner $agents,
        private readonly GateService $gates,
    ) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $run = $this->agents->run(FactoryStep::ArchitectureDesign, [
            'project' => ProjectContext::basic($project),
            'requirements' => ProjectContext::stageOutput($project, SdlcStage::Requirements),
            'feedback' => $record->feedback ?? '',
            'existing_state' => ProjectContext::existingState($project),
        ], $project);

        $record->update(['output' => $run->response, 'feedback' => null]);

        $this->gates->open(
            GateType::ArchitectureApproval,
            "Approve architecture — {$project->name}",
            $project,
            'Review the proposed architecture. Rejecting regenerates it using your note as feedback.',
            detail: $run->response,
            payload: ['agent_run_id' => $run->id],
        );

        return StageResult::awaitingApproval();
    }
}
