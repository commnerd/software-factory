<?php

namespace Tests\Feature;

use App\Enums\GateType;
use App\Enums\Lifecycle;
use App\Enums\SdlcStage;
use App\Exceptions\FactoryException;
use App\Models\AgentRun;
use App\Models\ApprovalGate;
use App\Models\ImprovementProposal;
use App\Models\Project;
use App\Services\Execution\Workspace;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** After the first build ships, work continues as numbered cycles: survey → choose → plan → TDD → QA → release → retrospective. */
class ImprovementCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
    }

    private function gate(Project $project, GateType $type): ApprovalGate
    {
        return $project->gates()->where('status', 'pending')->where('type', $type->value)->latest('id')->firstOrFail();
    }

    private function decide(Project $project, GateType $type, bool $approve, array $data = [], ?string $note = null): void
    {
        app(GateService::class)->decide($this->gate($project, $type), $approve, $note, 'tester', $data);
    }

    /** A greenfield project taken through its whole first build. */
    private function released(): Project
    {
        $project = Project::create(['name' => 'Demo', 'brief' => 'Greet people.', 'language' => 'php']);
        app(PipelineOrchestrator::class)->start($project);
        $this->decide($project, GateType::ArchitectureApproval, true);
        $this->decide($project, GateType::Deployment, true);

        return $project->fresh();
    }

    private function surveyedCycle(Project $project): Project
    {
        app(PipelineOrchestrator::class)->startCycle($project);

        return $project->fresh();
    }

    public function test_a_finished_build_idles_and_waits_for_the_next_cycle(): void
    {
        $project = $this->released();

        $this->assertSame('idle', $project->status);
        $this->assertSame(Lifecycle::Build, $project->lifecycle);
        $this->assertSame(0, $project->cycle);
    }

    public function test_starting_a_cycle_surveys_the_code_and_pauses_at_a_backlog_review(): void
    {
        $project = $this->surveyedCycle($this->released());

        $this->assertSame(Lifecycle::Improve, $project->lifecycle);
        $this->assertSame(1, $project->cycle);
        $this->assertSame(SdlcStage::Backlog, $project->current_stage);
        $this->assertSame('awaiting_approval', $project->status);

        $this->assertSame(3, $project->proposals()->where('cycle', 1)->count());
        $prompt = AgentRun::where('step', 'feature_survey')->value('prompt');
        $this->assertStringContainsString('Improvement cycle: 1', $prompt);
        $this->assertStringContainsString('Greet people.', $prompt, 'the original brief is the product vision');

        $gate = $this->gate($project, GateType::BacklogSelection);
        $titles = collect($gate->payload['proposals'])->pluck('title');
        $this->assertCount(3, $titles);
        // pre-selection: high value-for-effort with impact >= 3, best first (the refactor is low impact)
        $recommended = ImprovementProposal::whereIn('id', $gate->payload['recommended_ids'])->pluck('title')->all();
        $this->assertContains('Add export endpoint (cycle 1)', $recommended);
        $this->assertNotContains('Tidy configuration loading (cycle 1)', $recommended);
    }

    public function test_only_the_chosen_proposals_are_planned_built_released_and_reviewed(): void
    {
        $project = $this->surveyedCycle($this->released());
        $export = $project->proposals()->where('title', 'like', 'Add export%')->firstOrFail();

        $this->decide($project, GateType::BacklogSelection, true, ['selected_ids' => [$export->id]]);

        $project->refresh();
        // planned only the chosen one, as this cycle's tasks, linked back to the proposal
        $tasks = $project->cycleTasks()->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($export->id, $tasks[0]->improvement_proposal_id);
        $this->assertSame('done', $tasks[0]->tdd_phase->value);
        $this->assertSame(1, $project->sprints()->where('cycle', 1)->count());
        $this->assertSame(['red', 'green', 'refactor'], $tasks[0]->testSuites()->orderBy('id')->pluck('phase')->all());

        // unchosen proposals stay in the backlog
        $this->assertSame(['deferred', 'deferred'], $project->proposals()->where('id', '!=', $export->id)->pluck('status')->all());

        // QA ran, then the release gate is waiting; it targets a cycle branch
        $this->assertSame(SdlcStage::DeploymentPrep, $project->current_stage);
        $this->assertStringContainsString('factory/cycle-1', $this->gate($project, GateType::Deployment)->summary);
        $this->assertSame('in_progress', $export->fresh()->status);

        $this->decide($project, GateType::Deployment, true);

        $project->refresh();
        $this->assertSame('idle', $project->status, 'retrospective ran and the cycle closed');
        $this->assertSame('done', $export->fresh()->status);
        $this->assertSame(1, $export->fresh()->built_in_cycle);
        $this->assertStringContainsString('Cycle 1:', $project->stage(SdlcStage::Retrospective)->output);
        $this->assertDatabaseHas('agent_runs', ['step' => 'cycle_retrospective']);

        $branch = new Process(['git', 'branch', '--show-current'], app(Workspace::class)->path($project));
        $branch->run();
        $this->assertSame('factory/cycle-1', trim($branch->getOutput()));
    }

    public function test_each_cycle_keeps_its_own_stage_records_and_the_build_history_is_untouched(): void
    {
        $project = $this->released();
        $buildArchitecture = $project->knowledge(SdlcStage::Architecture);
        $this->assertNotSame('', $buildArchitecture);

        $project = $this->surveyedCycle($project);

        $this->assertSame('completed', $project->stage(SdlcStage::Survey, 1)->status);
        $this->assertSame('pending', $project->stage(SdlcStage::Survey, 2)->status);
        $this->assertSame('completed', $project->stage(SdlcStage::Architecture, 0)->status);
        $this->assertSame($buildArchitecture, $project->knowledge(SdlcStage::Architecture), 'later cycles still see the original design');
    }

    public function test_the_next_survey_learns_from_the_backlog_history_and_retrospective(): void
    {
        $project = $this->surveyedCycle($this->released());
        $export = $project->proposals()->where('title', 'like', 'Add export%')->firstOrFail();
        $this->decide($project, GateType::BacklogSelection, true, ['selected_ids' => [$export->id]]);
        $this->decide($project, GateType::Deployment, true);

        $project = $this->surveyedCycle($project->fresh());
        $this->assertSame(2, $project->cycle);

        $prompt = AgentRun::where('step', 'feature_survey')->latest('id')->value('prompt');
        $this->assertStringContainsString('Improvement cycle: 2', $prompt);
        $this->assertStringContainsString('Add export endpoint (cycle 1)', $prompt, 'built last cycle: listed under history so it is not proposed again');
        $this->assertStringContainsString('Cover core module with tests (cycle 1)', $prompt, 'deferred: still on the open backlog');
        $this->assertStringContainsString('Next survey focus', $prompt, 'the retrospective feeds the survey');

        // carried-over items appear next to the new ones in the review
        $gate = $this->gate($project, GateType::BacklogSelection);
        $statuses = collect($gate->payload['proposals'])->groupBy('status')->map->count();
        $this->assertSame(2, $statuses['deferred']);
        $this->assertSame(3, $statuses['proposed']);
    }

    public function test_choosing_nothing_closes_the_cycle_without_building(): void
    {
        $project = $this->surveyedCycle($this->released());
        $buildRuns = AgentRun::where('step', 'tdd_red')->count(); // from the first build

        $this->decide($project, GateType::BacklogSelection, true, ['selected_ids' => []]);

        $project->refresh();
        $this->assertSame($buildRuns, AgentRun::where('step', 'tdd_red')->count(), 'nothing was built');
        $this->assertSame('idle', $project->status);
        $this->assertSame(0, $project->cycleTasks()->count());
        $this->assertStringContainsString('skipped', $project->stage(SdlcStage::Retrospective)->output);
        $this->assertSame(3, $project->proposals()->where('status', 'deferred')->count(), 'everything stays on the backlog');
    }

    public function test_rejecting_the_backlog_review_resurveys_with_the_reviewers_focus(): void
    {
        $project = $this->surveyedCycle($this->released());

        $this->decide($project, GateType::BacklogSelection, false, [], 'Focus on security');

        $this->assertSame(2, AgentRun::where('step', 'feature_survey')->count());
        $this->assertStringContainsString('Focus on security', AgentRun::where('step', 'feature_survey')->latest('id')->value('prompt'));
        $this->assertSame(2, $project->gates()->where('type', GateType::BacklogSelection->value)->count());
    }

    public function test_a_cycle_can_only_start_when_idle(): void
    {
        $project = Project::create(['name' => 'P', 'brief' => 'x', 'language' => 'php']);

        $this->expectException(FactoryException::class);
        app(PipelineOrchestrator::class)->startCycle($project);
    }

    public function test_a_blocked_task_means_the_proposal_is_reported_as_failed_not_done(): void
    {
        $project = $this->surveyedCycle($this->released());
        $export = $project->proposals()->where('title', 'like', 'Add export%')->firstOrFail();
        $this->decide($project, GateType::BacklogSelection, true, ['selected_ids' => [$export->id]]);

        $project->cycleTasks()->update(['tdd_phase' => 'blocked']); // a reviewer skipped it
        $this->decide($project, GateType::Deployment, true);

        $this->assertSame('failed', $export->fresh()->status);
        $this->assertNull($export->fresh()->built_in_cycle);
    }

    public function test_scheduler_starts_cycles_only_for_idle_projects_whose_interval_elapsed(): void
    {
        $due = $this->released();
        $due->update(['cycle_interval_days' => 7, 'last_cycle_completed_at' => now()->subDays(8)]);
        $notYet = $this->released();
        $notYet->update(['cycle_interval_days' => 7, 'last_cycle_completed_at' => now()->subDay()]);
        $manual = $this->released();
        $manual->update(['last_cycle_completed_at' => now()->subYear()]);

        $this->artisan('factory:cycle-due')->assertSuccessful();

        $this->assertSame(1, $due->fresh()->cycle);
        $this->assertSame(0, $notYet->fresh()->cycle);
        $this->assertSame(0, $manual->fresh()->cycle, 'projects without an interval are never started automatically');
    }

    public function test_dashboard_actions_start_a_cycle_and_curate_the_backlog(): void
    {
        $project = $this->released();

        $this->post("/projects/{$project->id}/cycle")->assertRedirect();
        $this->assertSame(1, $project->fresh()->cycle);

        $this->post("/projects/{$project->id}/cycle")->assertSessionHas('error'); // already running

        $this->post("/projects/{$project->id}/ideas", ['title' => 'Dark mode', 'category' => 'feature', 'summary' => 'Users ask', 'impact' => 4, 'effort' => 2])->assertRedirect();
        $idea = $project->proposals()->where('title', 'Dark mode')->firstOrFail();
        $this->assertSame('user', $idea->source);
        $this->assertSame(4.0, $idea->score);

        $this->patch("/proposals/{$idea->id}", ['action' => 'dismiss']);
        $this->assertSame('rejected', $idea->fresh()->status);
        $this->patch("/proposals/{$idea->id}", ['action' => 'restore']);
        $this->assertSame('proposed', $idea->fresh()->status);

        $idea->update(['status' => 'in_progress']);
        $this->patch("/proposals/{$idea->id}", ['action' => 'dismiss'])->assertSessionHas('error');
        $this->assertSame('in_progress', $idea->fresh()->status);
    }

    public function test_improvement_pages_render(): void
    {
        $project = $this->surveyedCycle($this->released());

        $this->get("/projects/{$project->id}")->assertInertia(fn ($page) => $page
            ->component('Projects/Show')
            ->where('project.lifecycle', 'improve')
            ->where('project.cycle', 1)
            ->has('proposals', 3)
            ->has('stages', 7)
            ->where('stages.0.value', 'survey')
            ->where('gates.0.type', 'backlog_selection'));

        $this->get('/')->assertOk();
        $this->get('/approvals')->assertOk();
    }

    public function test_proposal_score_rewards_impact_and_penalises_effort(): void
    {
        $this->assertSame(10.0, ImprovementProposal::score(5, 1));
        $this->assertSame(2.0, ImprovementProposal::score(5, 5));
        $this->assertGreaterThan(ImprovementProposal::score(3, 3), ImprovementProposal::score(3, 2));
        $this->assertSame(ImprovementProposal::score(5, 1), ImprovementProposal::score(9, 0), 'out-of-range values are clamped');
    }
}
