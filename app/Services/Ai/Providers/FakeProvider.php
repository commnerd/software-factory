<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\CompletionRequest;
use App\Services\Ai\CompletionResponse;
use App\Services\Ai\Contracts\AiProvider;

/**
 * Deterministic offline "model". Lets the whole pipeline (and the test-suite) run
 * without API keys: it returns plausible, correctly-shaped output for every step.
 */
class FakeProvider implements AiProvider
{
    public function complete(CompletionRequest $request): CompletionResponse
    {
        $content = match ($request->step) {
            'requirements_analysis' => $this->requirements(),
            'architecture_design' => $this->architecture(),
            'task_breakdown' => json_encode($this->breakdown(), JSON_PRETTY_PRINT),
            'tdd_red' => json_encode(['files' => [['path' => 'tests/GreetingTest.txt', 'content' => 'failing test placeholder']], 'notes' => 'Wrote failing tests first.']),
            'tdd_green' => json_encode(['files' => [['path' => 'src/greeting.txt', 'content' => 'implementation placeholder']], 'notes' => 'Minimal implementation.']),
            'tdd_refactor' => json_encode(['files' => [['path' => 'src/greeting.txt', 'content' => 'refactored placeholder']], 'notes' => 'Extracted helper, behaviour unchanged.']),
            'qa_review' => json_encode(['verdict' => 'pass', 'summary' => 'All acceptance criteria are covered by passing tests.', 'risks' => []]),
            'ci_workflow' => json_encode(['files' => []]),
            'deployment_prep' => "## Release checklist\n- [x] Tests green\n- [x] CI workflows generated\n- [ ] Provision target environment\n- [ ] Configure repository secrets",
            'self_analysis' => json_encode(['proposals' => [[
                'title' => 'Add a smoke test for the skill playground',
                'rationale' => 'The playground is the main way users tune agents but has no regression test.',
                'plan' => 'Add one unit test asserting that compiled prompts contain interpolated variables.',
            ]]]),
            'self_implementation' => json_encode(['files' => [[
                'path' => 'tests/Unit/FactoryFakeProposalTest.php',
                'content' => "<?php\n\nnamespace Tests\\Unit;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass FactoryFakeProposalTest extends TestCase\n{\n    public function test_it_runs(): void\n    {\n        \$this->assertTrue(true);\n    }\n}\n",
            ]]]),
            default => 'OK (fake provider): '.mb_substr($request->prompt, 0, 200),
        };

        return new CompletionResponse(
            $content,
            "[fake] Reasoning about step '{$request->step}': decomposed the request, checked constraints, produced the structured result below.",
            (int) ceil(strlen($request->prompt.$request->system) / 4),
            (int) ceil(strlen($content) / 4),
        );
    }

    private function requirements(): string
    {
        return <<<'MD'
        # Requirements

        ## Functional
        1. Users can create and view the core domain objects described in the brief.
        2. All behaviour is exposed through a documented interface.

        ## Non-functional
        - 100% of acceptance criteria covered by automated tests.
        - Runs inside a container with no host dependencies.

        ## Open questions
        - None blocking.
        MD;
    }

    private function architecture(): string
    {
        return <<<'MD'
        # Architecture

        - **Style:** layered (interface → application services → persistence).
        - **Testing:** unit tests for services, integration tests for the interface.
        - **Packaging:** single container image, CI via GitHub Actions.

        ## Key decisions
        1. Keep persistence behind an interface so it can be swapped.
        2. No framework-specific code in the domain layer.
        MD;
    }

    private function breakdown(): array
    {
        return ['sprints' => [[
            'name' => 'Sprint 1 — Foundations',
            'goal' => 'Deliver the walking skeleton.',
            'tasks' => [
                [
                    'title' => 'Greeting service',
                    'description' => 'Return a greeting for a given name.',
                    'acceptance_criteria' => ['Returns "Hello, <name>!"', 'Empty name falls back to "World"'],
                    'files' => ['src/greeting.txt', 'tests/GreetingTest.txt'],
                ],
                [
                    'title' => 'Farewell service',
                    'description' => 'Return a farewell for a given name.',
                    'acceptance_criteria' => ['Returns "Goodbye, <name>!"'],
                    'files' => ['src/farewell.txt'],
                ],
            ],
        ]]];
    }
}
