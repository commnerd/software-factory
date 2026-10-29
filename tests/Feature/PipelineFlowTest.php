<?php

namespace Tests\Feature;

use App\Enums\GateType;
use App\Enums\SdlcStage;
use App\Enums\TddPhase;
use App\Models\AgentRun;
use App\Models\ApprovalGate;
use App\Models\ModelConfiguration;
use App\Models\Project;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Runs the whole SDLC against the fake provider + fake executor (QUEUE_CONNECTION=sync). */
class PipelineFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
    }

    private function project(array $attrs = []): Project
    {
        return Project::create($attrs + ['name' => 'Demo', 'brief' => 'Greet people.', 'language' => 'php']);
    }

    private function pendingGate(Project $project, GateType $type): ApprovalGate
    {
        return $project->gates()->where('status', 'pending')->where('type', $type->value)->latest('id')->firstOrFail();
    }

    public function test_pipeline_pauses_at_architecture_and_runs_to_deployment_gate_then_completes(): void
    {
        $project = $this->project();
        app(PipelineOrchestrator::class)->start($project);

        $project->refresh();
        $this->assertSame('awaiting_approval', $project->status);
        $this->assertSame(SdlcStage::Architecture, $project->current_stage);
        $this->assertSame('completed', $project->stage(SdlcStage::Requirements)->status);
        $this->assertSame(0, $project->tasks()->count(), 'nothing is planned before the architecture is approved');

        // Human approves the architecture -> task breakdown, TDD, QA, deployment prep run.
        app(GateService::class)->decide($this->pendingGate($project, GateType::ArchitectureApproval), true);

        $project->refresh();
        $this->assertSame('awaiting_approval', $project->status);
        $this->assertSame(SdlcStage::DeploymentPrep, $project->current_stage);
        $this->assertSame(2, $project->tasks()->where('tdd_phase', TddPhase::Done->value)->count());

        $paths = $project->artifacts()->pluck('path')->all();
        $this->assertContains('.github/workflows/ci.yml', $paths);
        $this->assertContains('.github/workflows/deploy.yml', $paths);

        app(GateService::class)->decide($this->pendingGate($project, GateType::Deployment), true);

        $project->refresh();
        // A finished build is not "done": the project idles, ready for continuous improvement.
        $this->assertSame('idle', $project->status);
        $this->assertSame('completed', $project->stage(SdlcStage::DeploymentPrep)->status);
        $this->assertNotNull($project->last_cycle_completed_at);
        $this->assertSame(0, $project->cycle);
    }

    public function test_tdd_order_is_red_then_green_then_refactor_per_task(): void
    {
        $project = $this->project();
        app(PipelineOrchestrator::class)->start($project);
        app(GateService::class)->decide($this->pendingGate($project, GateType::ArchitectureApproval), true);

        $task = $project->tasks()->first();
        $phases = $task->testSuites()->orderBy('id')->pluck('phase')->all();

        $this->assertSame(['red', 'green', 'refactor'], $phases);

        $red = $task->testSuites()->where('phase', 'red')->first();
        $this->assertNotSame('passed', $red->status, 'red phase must fail before implementation exists');

        $steps = AgentRun::where('project_task_id', $task->id)->orderBy('id')->pluck('step')->all();
        $this->assertSame(['tdd_red', 'tdd_green', 'tdd_refactor'], $steps);

        // QA ran the full suite
        $this->assertTrue($project->testSuites()->where('phase', 'qa')->where('status', 'passed')->exists());
    }

    public function test_rejecting_architecture_regenerates_it_with_feedback(): void
    {
        $project = $this->project();
        app(PipelineOrchestrator::class)->start($project);

        app(GateService::class)->decide($this->pendingGate($project, GateType::ArchitectureApproval), false, 'Use event sourcing');

        $project->refresh();
        $this->assertSame(SdlcStage::Architecture, $project->current_stage);
        $this->assertSame('awaiting_approval', $project->status);
        $this->assertSame(2, $project->gates()->where('type', GateType::ArchitectureApproval->value)->count());
        $this->assertSame(2, $project->stage(SdlcStage::Architecture)->runs);

        // The reviewer's note was injected into the regenerated prompt.
        $this->assertTrue(AgentRun::where('step', 'architecture_design')->where('prompt', 'like', '%Use event sourcing%')->exists());
    }

    public function test_budget_exhaustion_opens_a_cost_override_gate_and_resumes_on_approval(): void
    {
        // Make the fake model cost money so spend accumulates.
        ModelConfiguration::where('provider', 'fake')->update(['input_cost_per_mtok' => 5000, 'output_cost_per_mtok' => 5000]);
        $project = $this->project(['budget_limit_usd' => 0.01]);

        app(PipelineOrchestrator::class)->start($project);

        $project->refresh();
        $this->assertSame('awaiting_approval', $project->status);
        $gate = $this->pendingGate($project, GateType::CostOverride);
        $this->assertGreaterThan(0.01, (float) $project->spent_usd);

        app(GateService::class)->decide($gate, true, null, 'tester', ['new_budget' => 1000]);

        $project->refresh();
        $this->assertEquals(1000, (float) $project->budget_limit_usd);
        $this->assertNotSame('failed', $project->status);
        $this->assertTrue($project->stage(SdlcStage::Requirements)->status === 'completed' || $project->current_stage !== SdlcStage::Requirements);
    }

    public function test_rejecting_cost_override_pauses_the_project(): void
    {
        ModelConfiguration::where('provider', 'fake')->update(['input_cost_per_mtok' => 5000, 'output_cost_per_mtok' => 5000]);
        $project = $this->project(['budget_limit_usd' => 0.01]);
        app(PipelineOrchestrator::class)->start($project);

        app(GateService::class)->decide($this->pendingGate($project, GateType::CostOverride), false);

        $this->assertSame('paused', $project->fresh()->status);
    }

    public function test_a_gate_can_only_be_decided_once(): void
    {
        $project = $this->project();
        app(PipelineOrchestrator::class)->start($project);
        $gate = $this->pendingGate($project, GateType::ArchitectureApproval);

        app(GateService::class)->decide($gate, true);

        $this->expectException(\App\Exceptions\FactoryException::class);
        app(GateService::class)->decide($gate->fresh(), true);
    }
}
