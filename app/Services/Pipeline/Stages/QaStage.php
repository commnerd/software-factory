<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\GateType;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Execution\TestRunner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\StageResult;

/** Full-suite run in a clean container + an AI review; anything short of 100% green stops here. */
class QaStage implements StageHandler
{
    public function __construct(
        private readonly TestRunner $tests,
        private readonly AgentRunner $agents,
        private readonly GateService $gates,
    ) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $suite = $this->tests->run($project, 'qa');
        $green = $suite->isGreen() && $suite->total > 0;

        $review = $this->agents->run(FactoryStep::QaReview, [
            'project' => ProjectContext::basic($project),
            'requirements' => ProjectContext::stageOutput($project, \App\Enums\SdlcStage::Requirements),
            'test_output' => $suite->output,
            'tests_green' => $green,
            'files' => ProjectContext::files($project),
        ], $project);

        $verdict = $review->json()['verdict'] ?? ($green ? 'pass' : 'fail');
        $record->update(['output' => "Suite: {$suite->passed}/{$suite->total} passed ({$suite->status}).\n\n".$review->response]);

        if ($green && $verdict === 'pass') {
            return StageResult::completed();
        }

        $this->gates->open(
            GateType::Intervention,
            "QA did not pass — {$project->name}",
            $project,
            $green
                ? 'Tests are green but the QA reviewer raised blockers. Approve to send unfinished tasks back through TDD; reject to pause the project.'
                : "Full suite is not 100% green ({$suite->passed}/{$suite->total}). Approve to send unfinished tasks back through TDD; reject to pause the project.",
            detail: $review->response."\n\n--- test output ---\n".$suite->output,
            payload: ['kind' => 'qa_failed'],
        );

        return StageResult::awaitingApproval();
    }
}
