<?php

namespace Tests\Feature;

use App\Enums\GateType;
use App\Enums\Lifecycle;
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

/** A repository that already has code is assessed, then improved in cycles — never rebuilt from scratch. */
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
        return Project::create($attrs + ['name' => 'Imported', 'language' => 'auto', 'repository_url' => $this->makeRepo($files)]);
    }

    private function gate(Project $project, GateType $type): ApprovalGate
    {
        return $project->gates()->where('status', 'pending')->where('type', $type->value)->latest('id')->firstOrFail();
    }

    public function test_greenfield_project_skips_assessment_and_follows_the_build_lifecycle(): void
    {
        $project = Project::create(['name' => 'New', 'brief' => 'x', 'language' => 'php']);
        app(PipelineOrchestrator::class)->start($project);

        $this->assertSame('completed', $project->stage(SdlcStage::Assessment)->status);
        $this->assertFalse($project->fresh()->existing_code);
        $this->assertSame(Lifecycle::Build, $project->fresh()->lifecycle);
        $this->assertDatabaseMissing('agent_runs', ['step' => 'project_assessment']);
        $this->assertDatabaseMissing('agent_runs', ['step' => 'feature_survey']);
        $this->assertSame(SdlcStage::Architecture, $project->fresh()->current_stage);
    }

    public function test_empty_repository_is_treated_as_greenfield(): void
    {
        $project = Project::create(['name' => 'Empty', 'brief' => 'x', 'language' => 'php', 'repository_url' => $this->makeRepo([])]);
        app(PipelineOrchestrator::class)->start($project);

        $this->assertFalse($project->fresh()->existing_code);
        $this->assertDatabaseMissing('agent_runs', ['step' => 'project_assessment']);
    }

    public function test_existing_code_is_imported_and_assessed_but_nothing_is_built_until_confirmed(): void
    {
        $project = $this->create($this->mature());
        app(PipelineOrchestrator::class)->start($project);

        $project->refresh();
        $this->assertSame('awaiting_approval', $project->status);
        $this->assertSame(SdlcStage::Assessment, $project->current_stage);
        $this->assertTrue($project->existing_code);

        $this->assertSame('php', $project->language, 'auto-detected from composer.json');
        $this->assertSame('Laravel', $project->framework);
        $this->assertTrue($project->artifacts()->where('path', 'src/Invoice.php')->exists());
        $this->assertSame('passed', $project->testSuites()->where('phase', 'baseline')->value('status'));

        $prompt = AgentRun::where('step', 'project_assessment')->value('prompt');
        $this->assertStringContainsString('"test_file_count": 1', $prompt);
        $this->assertStringContainsString('.github/workflows/ci.yml', $prompt);

        foreach (['requirements_analysis', 'architecture_design', 'feature_survey', 'tdd_red'] as $step) {
            $this->assertDatabaseMissing('agent_runs', ['step' => $step]);
        }
    }

    public function test_confirming_the_assessment_skips_the_build_lifecycle_and_starts_improvement_cycle_one(): void
    {
        $project = $this->create($this->mature());
        app(PipelineOrchestrator::class)->start($project);

        app(GateService::class)->decide($this->gate($project, GateType::AssessmentReview), true);

        $project->refresh();
        $this->assertSame(Lifecycle::Improve, $project->lifecycle);
        $this->assertSame(1, $project->cycle);
        $this->assertSame(SdlcStage::Backlog, $project->current_stage, 'surveyed, now waiting for the human to choose');
        $this->assertSame('awaiting_approval', $project->status);

        // The build stages never run for an imported project.
        foreach (['requirements_analysis', 'architecture_design'] as $step) {
            $this->assertDatabaseMissing('agent_runs', ['step' => $step]);
        }
        $this->assertDatabaseHas('agent_runs', ['step' => 'feature_survey']);

        // Documents recovered from the repo are carried forward as project knowledge.
        $this->assertStringContainsString('reverse-engineered', $project->knowledge(SdlcStage::Requirements));
        $this->assertStringContainsString('Architecture (from docs/)', $project->knowledge(SdlcStage::Architecture));

        // Survey prompt carries the real code facts, and the vision-less project is still surveyed.
        $prompt = AgentRun::where('step', 'feature_survey')->value('prompt');
        $this->assertStringContainsString('Improvement cycle: 1', $prompt);
        $this->assertStringContainsString('"test_file_count": 1', $prompt);
    }

    public function test_assessment_gaps_become_backlog_proposals(): void
    {
        $project = $this->create(['README.md' => '# App', 'composer.json' => '{}', 'src/Legacy.php' => '<?php']);
        app(PipelineOrchestrator::class)->start($project);
        app(GateService::class)->decide($this->gate($project, GateType::AssessmentReview), true);

        $titles = $project->proposals()->where('source', 'assessment')->pluck('title')->all();

        $this->assertContains('Characterize existing behaviour', $titles);
        $this->assertContains('Add missing feature', $titles);
        $this->assertContains('Document the architecture', $titles, 'no docs/ => a docs proposal');
        $this->assertContains('Add a CI pipeline', $titles);
        $this->assertSame('tests', $project->proposals()->where('title', 'Characterize existing behaviour')->value('category'));
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
