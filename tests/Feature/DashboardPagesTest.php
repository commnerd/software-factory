<?php

namespace Tests\Feature;

use App\Models\AiSkill;
use App\Models\Project;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
    }

    public function test_every_page_renders_its_inertia_component(): void
    {
        $project = Project::create(['name' => 'Demo', 'brief' => 'x', 'language' => 'php']);
        app(PipelineOrchestrator::class)->start($project); // leaves an architecture gate + agent runs
        $run = $project->agentRuns()->first();
        $skill = AiSkill::first();

        foreach ([
            '/' => 'Dashboard',
            '/projects' => 'Projects/Index',
            '/projects/create' => 'Projects/Create',
            "/projects/{$project->id}" => 'Projects/Show',
            '/models' => 'Models/Index',
            '/skills' => 'Skills/Index',
            '/skills/create' => 'Skills/Edit',
            "/skills/{$skill->id}" => 'Skills/Edit',
            '/approvals' => 'Approvals/Index',
            "/runs/{$run->id}" => 'AgentRuns/Show',
            '/self' => 'SelfIteration/Index',
        ] as $url => $component) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_project_page_exposes_pending_gate_and_agent_thoughts(): void
    {
        $project = Project::create(['name' => 'Demo', 'brief' => 'x', 'language' => 'php']);
        app(PipelineOrchestrator::class)->start($project);

        $this->get("/projects/{$project->id}")->assertInertia(fn (Assert $page) => $page
            ->where('project.status', 'awaiting_approval')
            ->where('gates.0.type', 'architecture_approval')
            ->where('gates.0.status', 'pending')
            ->where('agentRuns.0.has_thoughts', true)
            ->has('stages', 6));
    }

    public function test_approving_over_http_resumes_the_pipeline(): void
    {
        $project = Project::create(['name' => 'Demo', 'brief' => 'x', 'language' => 'php']);
        app(PipelineOrchestrator::class)->start($project);
        $gate = $project->gates()->firstOrFail();

        $this->post("/approvals/{$gate->id}/decide", ['decision' => 'approve'])->assertRedirect();

        $this->assertSame('approved', $gate->fresh()->status);
        $this->assertSame('deployment_prep', $project->fresh()->current_stage->value);
    }

    public function test_project_creation_validates_and_can_autostart(): void
    {
        $this->post('/projects', [])->assertSessionHasErrors(['name', 'brief', 'language']);
        $this->post('/projects', ['name' => 'X', 'brief' => 'y', 'language' => 'cobol'])->assertSessionHasErrors('language');

        $this->post('/projects', ['name' => 'Auto', 'brief' => 'Do it', 'language' => 'go', 'start' => true])->assertRedirect();

        $project = Project::where('name', 'Auto')->firstOrFail();
        $this->assertSame('awaiting_approval', $project->status);
    }

    public function test_model_routing_can_be_saved_from_the_dashboard(): void
    {
        $model = \App\Models\ModelConfiguration::where('provider', 'fake')->firstOrFail();

        $this->put('/models/routing', ['routes' => ['tdd_green' => $model->id], 'fallbacks' => []])->assertRedirect();

        $this->assertDatabaseHas('model_routes', ['project_id' => null, 'step' => 'tdd_green', 'model_configuration_id' => $model->id]);
    }

    public function test_access_password_protects_the_dashboard_when_configured(): void
    {
        config(['factory.access_password' => 'secret']);

        $this->get('/')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Basic '.base64_encode('any:secret')])->get('/')->assertOk();
    }
}
