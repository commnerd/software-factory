<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\StageResult;

class RequirementsStage implements StageHandler
{
    public function __construct(private readonly AgentRunner $agents) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $run = $this->agents->run(FactoryStep::RequirementsAnalysis, [
            'project' => ProjectContext::basic($project),
            'brief' => $project->brief ?? $project->description,
            'feedback' => $record->feedback ?? '',
        ], $project);

        $record->update(['output' => $run->response]);

        return StageResult::completed();
    }
}
