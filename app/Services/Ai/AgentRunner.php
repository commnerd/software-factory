<?php

namespace App\Services\Ai;

use App\Enums\FactoryStep;
use App\Exceptions\BudgetExceededException;
use App\Exceptions\FactoryException;
use App\Models\AgentRun;
use App\Models\AiSkill;
use App\Models\AiSkillVersion;
use App\Models\ModelConfiguration;
use App\Models\Project;
use App\Models\ProjectTask;
use Throwable;

/**
 * The single choke point for every LLM call:
 * skill (DB) + model (router) + variables -> provider -> audited AgentRun row.
 */
class AgentRunner
{
    public function __construct(
        private readonly SkillResolver $skills,
        private readonly SkillCompiler $compiler,
        private readonly ModelRouter $router,
        private readonly ProviderManager $providers,
    ) {}

    public function run(FactoryStep $step, array $variables, ?Project $project = null, ?ProjectTask $task = null): AgentRun
    {
        $skill = $this->skills->forStep($step);
        $route = $this->router->route($step, $project);

        return $this->execute(
            $skill,
            $this->skills->version($skill),
            $route['model'],
            $route['fallback'],
            $step->value,
            $variables,
            $project,
            $task,
        );
    }

    /** Dashboard playground: any skill, any version, any model — no project, never blocks on budget. */
    public function test(AiSkill $skill, AiSkillVersion $version, ModelConfiguration $model, array $variables): AgentRun
    {
        return $this->execute($skill, $version, $model, null, $skill->step ?? 'playground', $variables, null, null, isTest: true);
    }

    public function execute(
        AiSkill $skill,
        AiSkillVersion $version,
        ModelConfiguration $model,
        ?ModelConfiguration $fallback,
        string $step,
        array $variables,
        ?Project $project = null,
        ?ProjectTask $task = null,
        bool $isTest = false,
    ): AgentRun {
        if ($project) {
            $project->refresh();
            if ($project->budgetExceeded()) {
                throw new BudgetExceededException($project);
            }
        }

        $compiled = $this->compiler->compile($version, $variables);

        $run = AgentRun::create([
            'project_id' => $project?->id,
            'project_task_id' => $task?->id,
            'ai_skill_id' => $skill->id,
            'ai_skill_version_id' => $version->id,
            'model_configuration_id' => $model->id,
            'step' => $step,
            'status' => 'running',
            'system_prompt' => $compiled->system,
            'prompt' => $compiled->user,
            'is_test' => $isTest,
        ]);

        $started = microtime(true);

        try {
            [$response, $used] = $this->call($model, $fallback, $compiled, $step);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);

            throw $e instanceof FactoryException ? $e : new FactoryException($e->getMessage(), 0, $e);
        }

        [$content, $inlineThoughts] = $this->splitThinking($response->content);
        $thoughts = trim(($response->thinking ?? '')."\n".$inlineThoughts) ?: null;
        $cost = $used->cost($response->inputTokens, $response->outputTokens);

        $run->update([
            'model_configuration_id' => $used->id,
            'status' => 'completed',
            'response' => $content,
            'thoughts' => $thoughts,
            'input_tokens' => $response->inputTokens,
            'output_tokens' => $response->outputTokens,
            'cost_usd' => $cost,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        if ($project && $cost > 0) {
            $project->increment('spent_usd', $cost);
        }

        return $run;
    }

    /** @return array{0: CompletionResponse, 1: ModelConfiguration} */
    private function call(ModelConfiguration $model, ?ModelConfiguration $fallback, CompiledPrompt $compiled, string $step): array
    {
        foreach (array_filter([$model, $fallback]) as $candidate) {
            try {
                $response = $this->providers->forModel($candidate)->complete(new CompletionRequest(
                    model: $candidate->model,
                    prompt: $compiled->user,
                    system: $compiled->system,
                    parameters: $candidate->parameters ?? [],
                    step: $step,
                ));

                return [$response, $candidate];
            } catch (FactoryException $e) {
                $last = $e;
            }
        }

        throw $last;
    }

    /** @return array{0: string, 1: string} content without <thinking> blocks, and the extracted thoughts */
    private function splitThinking(string $content): array
    {
        $thoughts = [];
        $clean = preg_replace_callback('/<(thinking|think)>(.*?)<\/\1>/s', function ($m) use (&$thoughts) {
            $thoughts[] = trim($m[2]);

            return '';
        }, $content);

        return [trim($clean), implode("\n\n", $thoughts)];
    }
}
