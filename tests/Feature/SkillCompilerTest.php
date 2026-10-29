<?php

namespace Tests\Feature;

use App\Models\AiSkill;
use App\Services\Ai\SkillCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillCompilerTest extends TestCase
{
    use RefreshDatabase;

    private function skill(string $slug, string $template, ?string $system = null, string $kind = 'agent'): AiSkill
    {
        $skill = AiSkill::create(['slug' => $slug, 'name' => $slug, 'kind' => $kind, 'step' => $kind === 'agent' ? 'requirements_analysis' : null]);
        $skill->publish(['system_prompt' => $system, 'user_template' => $template]);

        return $skill->fresh('currentVersion');
    }

    public function test_it_interpolates_scalars_and_dot_paths(): void
    {
        $skill = $this->skill('s', 'Hi {{ name }} from {{ project.language }}!');

        $out = app(SkillCompiler::class)->compile($skill->currentVersion, ['name' => 'Ada', 'project' => ['language' => 'php']]);

        $this->assertSame('Hi Ada from php!', $out->user);
        $this->assertSame([], $out->missing);
    }

    public function test_arrays_become_json_and_booleans_become_words(): void
    {
        $skill = $this->skill('s', '{{ tasks }} / {{ green }}');

        $out = app(SkillCompiler::class)->compile($skill->currentVersion, ['tasks' => ['a' => 1], 'green' => true]);

        $this->assertStringContainsString('"a": 1', $out->user);
        $this->assertStringContainsString('true', $out->user);
    }

    public function test_missing_variables_are_reported(): void
    {
        $skill = $this->skill('s', 'A {{ one }} B {{ two }}');

        $out = app(SkillCompiler::class)->compile($skill->currentVersion, ['one' => 1]);

        $this->assertSame(['two'], $out->missing);
        $this->assertSame('A 1 B ', $out->user);
    }

    public function test_snippets_are_included_from_the_database(): void
    {
        $this->skill('rules', 'Be nice to {{ name }}.', kind: 'snippet');
        $skill = $this->skill('main', 'Task. {{> rules }}', 'System. {{> rules }}');

        $out = app(SkillCompiler::class)->compile($skill->currentVersion, ['name' => 'Bob']);

        $this->assertSame('Task. Be nice to Bob.', $out->user);
        $this->assertSame('System. Be nice to Bob.', $out->system);
    }

    public function test_unknown_snippet_is_flagged_not_fatal(): void
    {
        $skill = $this->skill('main', 'X {{> nope }}');

        $out = app(SkillCompiler::class)->compile($skill->currentVersion);

        $this->assertContains('>nope', $out->missing);
    }

    public function test_variable_detection(): void
    {
        $found = app(SkillCompiler::class)->detectVariables('{{ a }} {{b.c}}', '{{ a }} {{> snippet }}');

        $this->assertSame(['a', 'b.c'], $found);
    }
}
