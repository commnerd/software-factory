<?php

namespace Tests\Feature;

use App\Enums\GateType;
use App\Enums\SdlcStage;
use App\Exceptions\FactoryException;
use App\Models\AgentRun;
use App\Models\ApprovalGate;
use App\Models\Project;
use App\Services\Import\RepositoryImporter;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Projects created from a repository that already has code must not be built from scratch. */
class ExistingRepositoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
    }

    private function mature(): array
    {
        return [
            'README.md' => "# Billing\nSends invoices.",
            'docs/architecture.md' => "# Design\nLayered.",
            'composer.json' => '{"require":{"laravel/framework":"^12"}}',
            'src/Invoice.php' => '<?php // TODO: tax',
            'tests/InvoiceTest.php' => '<?php',
            '.github/workflows/ci.yml' => "name: CI\njobs:\n  t:\n    runs-on: ubuntu-latest\n",
        ];
    }

    private function bare(): array
    {
        return ['composer.json' => '{"name":"x/y"}', 'src/Thing.php' => '<?php'];
    }

    private function create(array $files, array $attrs = []): Project
    {
        return Project::create($attrs + [
            'name' => 'Imported', 'language' => 'auto', 'repository_url' => $this->makeRepo($files),
        ]);
    }

    private function gate(Project $project, GateType $type): ApprovalGate
    {
        return $project->gates()->where('status', 'pending')->where('type', $type->value)->latest('id')->firstOrFail();
    }

    public function test_greenfield_project_skips_assessment_entirely(): void
    {
        $project = Project::create(['name' => 'New', 'brief' => 'x', 'language' => 'php']);
        app(PipelineOrchestrator::class)->start($project);

        $this->assertSame('completed', $project->stage(SdlcStage::Assessment)->status);
        $this->assertFalse($project->fresh()->existing_code);
        $this->assertDatabaseMissing('agent_runs', ['step' => 'project_assessment']);
        $this->assertSame(SdlcStage::Architecture, $project->fresh()->current_stage);
    }

    public function test_empty_repository_is_treated_as_greenfield(): void
    {
        $project = Project::create(['name' => 'Empty', 'brief' => 'x', 'language' => 'php', 'repository_url' => $this->makeRepo([])]);
        app(PipelineOrchestrator::class)->start($project);

        $this->assertFalse($project->fresh()->existing_code);
        $this->assertDatabaseMissing('agent_runs', ['step' => 'project_assessment']);
    }

    public function test_existing_code_is_imported_assessed_and_confirmed_before_anything_is_built(): void
    {
        $project = $this->create($this->mature());
        app(PipelineOrchestrator::class)->start($project);

        $project->refresh();
        $this->assertSame('awaiting_approval', $project->status);
        $this->assertSame(SdlcStage::Assessment, $project->current_stage);
        $this->assertTrue($project->existing_code);

        // imported + detected, nothing generated yet
        $this->assertSame('php', $project->language, 'auto-detected from composer.json');
        $this->assertSame('Laravel', $project->framework);
        $this->assertTrue($project->artifacts()->where('path', 'src/Invoice.php')->exists());
        $this->assertDatabaseMissing('agent_runs', ['step' => 'requirements_analysis']);
        $this->assertSame(0, $project->tasks()->count());

        // facts reached the assessor
        $prompt = AgentRun::where('step', 'project_assessment')->value('prompt');
        $this->assertStringContainsString('"test_file_count": 1', $prompt);
        $this->assertStringContainsString('.github/workflows/ci.yml', $prompt);
        $this->assertSame('passed', $project->testSuites()->where('phase', 'baseline')->value('status'));

        $gate = $this->gate($project, GateType::AssessmentReview);
        $this->assertSame('qa', $gate->payload['recommended_stage'], 'docs, tests and CI exist and nothing is missing');
    }

    public function test_a_complete_repository_resumes_at_qa_without_regenerating_anything(): void
    {
        $project = $this->create($this->mature());
        app(PipelineOrchestrator::class)->start($project);

        app(GateService::class)->decide($this->gate($project, GateType::AssessmentReview), true);

        $project->refresh();
        // QA ran, then the pipeline stopped at the deployment gate
        $this->assertSame(SdlcStage::DeploymentPrep, $project->current_stage);
        $this->assertSame('awaiting_approval', $project->status);

        foreach (['requirements_analysis', 'architecture_design', 'task_breakdown', 'tdd_red', 'tdd_green', 'tdd_refactor'] as $step) {
            $this->assertDatabaseMissing('agent_runs', ['step' => $step]);
        }
        $this->assertDatabaseHas('agent_runs', ['step' => 'qa_review']);

        // documents recovered from the repo are carried forward
        $this->assertStringContainsString('reverse-engineered', $project->stage(SdlcStage::Requirements)->output);
        $this->assertStringContainsString('Architecture (from docs/)', $project->stage(SdlcStage::Architecture)->output);
        $this->assertSame('completed', $project->stage(SdlcStage::TddImplementation)->status);

        // the repo's own CI is left alone; only the missing deploy workflow is added
        $ci = file_get_contents(app(\App\Services\Execution\Workspace::class)->path($project).'/.github/workflows/ci.yml');
        $this->assertSame("name: CI\njobs:\n  t:\n    runs-on: ubuntu-latest\n", $ci);
        $this->assertTrue($project->artifacts()->where('path', '.github/workflows/deploy.yml')->exists());
        $this->assertStringContainsString('existing workflows kept untouched: .github/workflows/ci.yml', $this->gate($project, GateType::Deployment)->summary);
    }

    public function test_a_bare_repository_starts_at_requirements_and_feeds_the_code_into_the_prompts(): void
    {
        $project = $this->create($this->bare());
        app(PipelineOrchestrator::class)->start($project);

        $gate = $this->gate($project, GateType::AssessmentReview);
        $this->assertSame('requirements', $gate->payload['recommended_stage']);

        app(GateService::class)->decide($gate, true);

        $project->refresh();
        $this->assertSame(SdlcStage::Architecture, $project->current_stage, 'requirements ran, now waiting on architecture approval');

        $prompt = AgentRun::where('step', 'requirements_analysis')->value('prompt');
        $this->assertStringContainsString('imported from an existing repository', $prompt);
        $this->assertStringContainsString('Do NOT start from scratch', $prompt);

        app(GateService::class)->decide($this->gate($project, GateType::ArchitectureApproval), true);
        $this->assertStringContainsString('plan ONLY the remaining delta', AgentRun::where('step', 'task_breakdown')->value('prompt'));
    }

    public function test_the_reviewer_can_override_the_starting_stage(): void
    {
        $project = $this->create($this->mature());
        app(PipelineOrchestrator::class)->start($project);

        app(GateService::class)->decide($this->gate($project, GateType::AssessmentReview), true, null, 'tester', ['start_stage' => 'requirements']);

        $this->assertDatabaseHas('agent_runs', ['step' => 'requirements_analysis']);
        $this->assertSame(SdlcStage::Architecture, $project->fresh()->current_stage);
    }

    public function test_rejecting_the_assessment_reassesses_with_the_reviewers_note(): void
    {
        $project = $this->create($this->mature());
        app(PipelineOrchestrator::class)->start($project);

        app(GateService::class)->decide($this->gate($project, GateType::AssessmentReview), false, 'The payments module is unfinished');

        $this->assertSame(2, AgentRun::where('step', 'project_assessment')->count());
        $this->assertStringContainsString('The payments module is unfinished', AgentRun::where('step', 'project_assessment')->latest('id')->value('prompt'));
        $this->assertSame(2, $project->gates()->where('type', GateType::AssessmentReview->value)->count());
    }

    public function test_untested_code_becomes_characterization_tasks_that_do_not_need_a_red_phase(): void
    {
        $files = ['README.md' => '# App', 'docs/design.md' => '# D', 'composer.json' => '{}', 'src/Legacy.php' => '<?php'];
        $project = $this->create($files);
        app(PipelineOrchestrator::class)->start($project);

        $gate = $this->gate($project, GateType::AssessmentReview);
        $this->assertSame('tdd_implementation', $gate->payload['recommended_stage'], 'docs exist; only gap tasks remain');

        app(GateService::class)->decide($gate, true);

        $project->refresh();
        $this->assertSame(SdlcStage::DeploymentPrep, $project->current_stage);

        $char = $project->tasks()->where('type', 'characterization')->firstOrFail();
        $feature = $project->tasks()->where('type', 'feature')->firstOrFail();
        $this->assertSame('done', $char->tdd_phase->value);
        $this->assertSame(['characterize'], $char->testSuites()->pluck('phase')->all());
        $this->assertSame(['tdd_red'], AgentRun::where('project_task_id', $char->id)->pluck('step')->all(), 'existing behaviour: tests only, no implementation');

        $this->assertSame(['red', 'green', 'refactor'], $feature->testSuites()->orderBy('id')->pluck('phase')->all());
    }

    public function test_importer_refuses_unsafe_sources(): void
    {
        $importer = app(RepositoryImporter::class);

        foreach (['/etc', '/', 'file:///etc/passwd', 'ext::sh -c id', '-u evil', 'ftp://x/y'] as $bad) {
            try {
                $importer->assertSourceAllowed($bad);
                $this->fail("'{$bad}' should be refused");
            } catch (FactoryException) {
                $this->assertTrue(true);
            }
        }

        $importer->assertSourceAllowed('https://github.com/org/repo.git');
        $importer->assertSourceAllowed('git@github.com:org/repo.git');
        $importer->assertSourceAllowed($this->makeRepo(['a' => 'b'], 'ok'));
    }

    public function test_failed_clone_fails_the_project_with_a_clear_error(): void
    {
        $project = Project::create(['name' => 'Gone', 'language' => 'php', 'brief' => 'x', 'repository_url' => $this->scratch.'/repos/does-not-exist']);

        app(PipelineOrchestrator::class)->start($project);

        $project->refresh();
        $this->assertSame('failed', $project->status);
        $this->assertNotEmpty($project->last_error);
    }

    public function test_project_form_accepts_a_repository_without_a_brief_and_validates_auto(): void
    {
        $repo = $this->makeRepo($this->bare(), 'form');

        $this->post('/projects', ['name' => 'A', 'language' => 'auto', 'repository_url' => $repo])->assertRedirect();
        $this->assertDatabaseHas('projects', ['name' => 'A', 'language' => 'auto', 'repository_url' => $repo]);

        $this->post('/projects', ['name' => 'B', 'language' => 'auto', 'brief' => 'x'])->assertSessionHasErrors('language');
        $this->post('/projects', ['name' => 'C', 'language' => 'php'])->assertSessionHasErrors('brief');
        $this->post('/projects', ['name' => 'D', 'language' => 'php', 'brief' => 'x', 'repository_url' => '/etc'])->assertSessionHasErrors('repository_url');
    }
}
