<?php

namespace Tests\Feature;

use App\Enums\GateType;
use App\Exceptions\FactoryException;
use App\Models\ApprovalGate;
use App\Models\SelfProposal;
use App\Services\Pipeline\GateService;
use App\Services\SelfIteration\SelfIterationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SelfIterationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFactory();
    }

    public function test_analysis_creates_proposals_from_the_analyst_skill(): void
    {
        $found = app(SelfIterationService::class)->analyze('tests');

        $this->assertCount(1, $found);
        $this->assertSame('proposed', $found[0]->status);
        $this->assertDatabaseHas('agent_runs', ['step' => 'self_analysis']);
    }

    public function test_full_loop_tests_in_isolation_gates_then_stages_but_never_applies(): void
    {
        $self = app(SelfIterationService::class);
        $proposal = $self->analyze()[0];

        $self->process($proposal);

        $proposal->refresh();
        $this->assertSame('awaiting_approval', $proposal->status, $proposal->last_error ?? '');
        $this->assertSame('passed', $proposal->test_status);
        $this->assertDatabaseHas('test_suites', ['self_proposal_id' => $proposal->id, 'phase' => 'self']);

        $gate = ApprovalGate::where('self_proposal_id', $proposal->id)->where('status', 'pending')->firstOrFail();
        $this->assertSame(GateType::SelfModification, $gate->type);
        $this->assertStringContainsString('+++ b/tests/Unit/FactoryFakeProposalTest.php', $gate->detail);

        app(GateService::class)->decide($gate, true);

        $proposal->refresh();
        $this->assertSame('staged', $proposal->status);
        $this->assertFileExists($proposal->staged_path.'/changes.patch');
        $this->assertFileExists($proposal->staged_path.'/files/tests/Unit/FactoryFakeProposalTest.php');
        $this->assertFileDoesNotExist(base_path('tests/Unit/FactoryFakeProposalTest.php'), 'live checkout is untouched until a human applies the patch');
    }

    public function test_rejecting_the_gate_rejects_the_proposal(): void
    {
        $self = app(SelfIterationService::class);
        $proposal = $self->analyze()[0];
        $self->process($proposal);

        app(GateService::class)->decide(ApprovalGate::where('self_proposal_id', $proposal->id)->firstOrFail(), false);

        $this->assertSame('rejected', $proposal->fresh()->status);
    }

    public function test_forbidden_paths_are_refused(): void
    {
        $self = app(SelfIterationService::class);

        foreach (['.env', 'vendor/autoload.php', 'storage/logs/x.log', '../etc/passwd', 'composer.json', 'node_modules/x/y.js', 'app/../.env'] as $path) {
            try {
                $self->assertAllowed($path);
                $this->fail("{$path} should be refused");
            } catch (FactoryException) {
                $this->assertTrue(true);
            }
        }

        $self->assertAllowed('app/Services/Foo.php');
        $self->assertAllowed('tests/Feature/FooTest.php');
        $self->assertAllowed('resources/js/Pages/Foo.vue');
    }

    public function test_code_changes_without_tests_violate_the_tdd_policy(): void
    {
        $proposal = SelfProposal::create(['title' => 'No tests', 'plan' => 'x']);

        // Swap in an implementer that returns code only.
        $skill = \App\Models\AiSkill::where('step', 'self_implementation')->firstOrFail();
        $model = \App\Models\ModelConfiguration::where('provider', 'fake')->firstOrFail();
        $this->app->instance(\App\Services\Ai\ProviderManager::class, new class($this->app) extends \App\Services\Ai\ProviderManager
        {
            public function forModel(\App\Models\ModelConfiguration $model): \App\Services\Ai\Contracts\AiProvider
            {
                return new class implements \App\Services\Ai\Contracts\AiProvider
                {
                    public function complete(\App\Services\Ai\CompletionRequest $request): \App\Services\Ai\CompletionResponse
                    {
                        return new \App\Services\Ai\CompletionResponse(json_encode(['files' => [['path' => 'app/Foo.php', 'content' => '<?php']]]));
                    }
                };
            }
        });

        $this->expectException(FactoryException::class);
        $this->expectExceptionMessage('TDD policy');
        app(SelfIterationService::class)->implement($proposal);
    }
}
