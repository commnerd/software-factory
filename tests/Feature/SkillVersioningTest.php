<?php

namespace Tests\Feature;

use App\Enums\FactoryStep;
use App\Models\AiSkill;
use App\Services\Ai\SkillResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_creates_immutable_versions_and_moves_the_pointer(): void
    {
        $this->seedFactory();
        $skill = AiSkill::where('step', FactoryStep::RequirementsAnalysis->value)->firstOrFail();
        $v1 = $skill->currentVersion;

        $v2 = $skill->publish(['user_template' => 'edited {{ brief }}', 'change_note' => 'tuned']);

        $this->assertSame($v1->version + 1, $v2->version);
        $this->assertSame($v2->id, $skill->fresh()->current_version_id);
        $this->assertNotSame('edited {{ brief }}', $v1->fresh()->user_template, 'old versions never change');
    }

    public function test_resolver_sees_edits_immediately_and_supports_rollback(): void
    {
        $this->seedFactory();
        $skill = AiSkill::where('step', FactoryStep::TaskBreakdown->value)->firstOrFail();
        $original = $skill->currentVersion;

        $skill->publish(['user_template' => 'NEW']);
        $this->assertSame('NEW', app(SkillResolver::class)->forStep(FactoryStep::TaskBreakdown)->currentVersion->user_template);

        $skill->fresh()->activate($original);
        $this->assertSame($original->user_template, app(SkillResolver::class)->forStep(FactoryStep::TaskBreakdown)->currentVersion->user_template);
    }

    public function test_disabled_skills_are_not_resolved(): void
    {
        $this->seedFactory();
        AiSkill::where('step', FactoryStep::QaReview->value)->update(['is_active' => false]);

        $this->expectException(\App\Exceptions\FactoryException::class);
        app(SkillResolver::class)->forStep(FactoryStep::QaReview);
    }

    public function test_dashboard_edit_publishes_a_new_version(): void
    {
        $this->seedFactory();
        $skill = AiSkill::where('step', FactoryStep::QaReview->value)->firstOrFail();

        $this->put("/skills/{$skill->id}", [
            'name' => $skill->name, 'description' => '', 'system_prompt' => 'sys', 'user_template' => 'Check {{ files }}',
            'output_format' => 'json', 'change_note' => 'tighter',
        ])->assertRedirect();

        $skill->refresh();
        $this->assertSame(2, $skill->currentVersion->version);
        $this->assertSame('tighter', $skill->currentVersion->change_note);
        $this->assertSame([['name' => 'files']], $skill->currentVersion->variables);
    }

    public function test_playground_runs_unsaved_prompt_without_publishing(): void
    {
        $this->seedFactory();
        $skill = AiSkill::where('step', FactoryStep::QaReview->value)->firstOrFail();
        $model = \App\Models\ModelConfiguration::where('provider', 'fake')->firstOrFail();

        $this->post("/skills/{$skill->id}/test", [
            'system_prompt' => null, 'user_template' => 'Hello {{ who }}', 'output_format' => 'text',
            'model_id' => $model->id, 'variables' => '{"who":"world"}',
        ])->assertSessionHas('skill_test', fn ($r) => str_contains($r['prompt'], 'Hello world') && isset($r['response']));

        $this->assertSame(1, $skill->fresh()->versions()->count());
        $this->assertTrue(\App\Models\AgentRun::where('is_test', true)->exists());
    }
}
