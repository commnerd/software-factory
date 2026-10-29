<?php

namespace Database\Seeders;

use App\Enums\FactoryStep;
use App\Models\AiSkill;
use App\Models\ModelConfiguration;
use App\Models\ModelRoute;
use Database\Seeders\Data\SkillLibrary;
use Illuminate\Database\Seeder;

class FactorySeeder extends Seeder
{
    public function run(): void
    {
        $this->models();
        $this->skills();
        $this->routes();
    }

    private function models(): void
    {
        // Model ids / prices are starting points — edit them in Models.
        $rows = [
            ['Claude Haiku (cheap)', 'anthropic', 'claude-haiku-5-5', 'cheap', 1.0, 5.0, ['max_tokens' => 8192, 'temperature' => 0.2]],
            ['Claude Sonnet (standard)', 'anthropic', 'claude-sonnet-5-5', 'standard', 3.0, 15.0, ['max_tokens' => 16384, 'temperature' => 0.2]],
            ['Claude Fable (frontier)', 'anthropic', 'claude-fable-5-1', 'frontier', 15.0, 75.0, ['max_tokens' => 32000, 'thinking_budget' => 8000]],
            ['OpenAI-compatible (standard)', 'openai', 'gpt-4.1', 'standard', 2.0, 8.0, ['max_tokens' => 16384, 'temperature' => 0.2]],
            ['Ollama local (cheap)', 'ollama', 'qwen2.5-coder:7b', 'cheap', 0, 0, ['temperature' => 0.2]],
            ['Fake (offline demo)', 'fake', 'fake-1', 'standard', 0, 0, []],
        ];

        foreach ($rows as [$name, $provider, $model, $tier, $in, $out, $params]) {
            ModelConfiguration::updateOrCreate(
                ['provider' => $provider, 'model' => $model],
                [
                    'name' => $name, 'tier' => $tier, 'parameters' => $params,
                    'input_cost_per_mtok' => $in, 'output_cost_per_mtok' => $out,
                    // Only offer a provider by default when its credentials exist; the fake model is always on.
                    'is_active' => match ($provider) {
                        'anthropic' => (bool) config('factory.providers.anthropic.key'),
                        'openai' => (bool) config('factory.providers.openai.key'),
                        'ollama' => false,
                        default => true,
                    },
                ],
            );
        }

        $default = ModelConfiguration::where('is_active', true)->where('provider', '!=', 'fake')->first()
            ?? ModelConfiguration::where('provider', 'fake')->first();
        ModelConfiguration::query()->update(['is_default' => false]);
        $default?->update(['is_default' => true]);
    }

    private function skills(): void
    {
        foreach (SkillLibrary::all() as $data) {
            $skill = AiSkill::firstOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'],
                'description' => $data['description'],
                'kind' => $data['kind'],
                'step' => $data['step'],
            ]);

            $current = $skill->currentVersion;
            $payload = [
                'system_prompt' => $data['system_prompt'],
                'user_template' => $data['user_template'],
                'variables' => $data['variables'],
                'output_format' => $data['output_format'],
                'created_by' => 'seeder',
            ];

            if (! $current) {
                $skill->publish($payload + ['change_note' => 'Initial version']);
            } elseif ($current->created_by === 'seeder'
                && ($current->user_template !== $data['user_template'] || $current->system_prompt !== $data['system_prompt'])) {
                // Library prompts improved and nobody has edited this skill: roll the update forward.
                // (Skills a human has edited are never touched; their history stays as is.)
                $skill->publish($payload + ['change_note' => 'Library update']);
            }
        }
    }

    /** Global routes: each step goes to an active model of its default tier; falls back to the default model. */
    private function routes(): void
    {
        $default = ModelConfiguration::where('is_default', true)->first();

        foreach (FactoryStep::cases() as $step) {
            $model = ModelConfiguration::where('is_active', true)->where('tier', $step->defaultTier()->value)->where('provider', '!=', 'fake')->first() ?? $default;

            if ($model) {
                ModelRoute::firstOrCreate(
                    ['project_id' => null, 'step' => $step->value],
                    ['model_configuration_id' => $model->id],
                );
            }
        }
    }
}
