<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\GateType;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Execution\Workspace;
use App\Services\Pipeline\CiWorkflowGenerator;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\StageResult;

class DeploymentPrepStage implements StageHandler
{
    public function __construct(
        private readonly CiWorkflowGenerator $ci,
        private readonly Workspace $workspace,
        private readonly AgentRunner $agents,
        private readonly GateService $gates,
    ) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $workflows = $this->ci->generate($project);
        $written = $this->workspace->write($project, $workflows);

        $run = $this->agents->run(FactoryStep::DeploymentPrep, [
            'project' => ProjectContext::basic($project),
            'workflows' => $written,
            'feedback' => $record->feedback ?? '',
            'repository_url' => $project->repository_url ?? 'not configured',
        ], $project);

        $record->update(['output' => $run->response, 'feedback' => null]);

        $this->gates->open(
            GateType::Deployment,
            "Kick off deployment — {$project->name}",
            $project,
            'Tests are 100% green and GitHub Actions workflows were generated ('.implode(', ', $written).'). Approving commits the workspace and marks the release ready.',
            detail: $run->response,
            payload: ['workflows' => $written],
        );

        return StageResult::awaitingApproval();
    }
}
