<?php

namespace App\Services\Ai;

use App\Enums\FactoryStep;
use App\Exceptions\FactoryException;
use App\Models\ModelConfiguration;
use App\Models\ModelRoute;
use App\Models\Project;

/**
 * Resolves which model serves a step. Precedence:
 *   1. project route for the step      (per-project override)
 *   2. global route for the step       (project_id NULL)
 *   3. an active model of the step's default tier
 *   4. the model flagged is_default
 */
class ModelRouter
{
    public function resolve(FactoryStep $step, ?Project $project = null): ModelConfiguration
    {
        return $this->route($step, $project)['model'];
    }

    /** @return array{model: ModelConfiguration, fallback: ?ModelConfiguration, source: string} */
    public function route(FactoryStep $step, ?Project $project = null): array
    {
        if ($project && $hit = $this->routeFor($step, $project->id)) {
            return $hit + ['source' => 'project'];
        }

        if ($hit = $this->routeFor($step, null)) {
            return $hit + ['source' => 'global'];
        }

        $tiered = ModelConfiguration::where('is_active', true)->where('tier', $step->defaultTier()->value)->orderBy('id')->first();
        if ($tiered) {
            return ['model' => $tiered, 'fallback' => null, 'source' => 'tier'];
        }

        $default = ModelConfiguration::where('is_active', true)->orderByDesc('is_default')->orderBy('id')->first();
        if ($default) {
            return ['model' => $default, 'fallback' => null, 'source' => 'default'];
        }

        throw new FactoryException("No active model configuration available for step {$step->value}.");
    }

    private function routeFor(FactoryStep $step, ?int $projectId): ?array
    {
        $route = ModelRoute::with(['modelConfiguration', 'fallback'])
            ->where('step', $step->value)
            ->where('project_id', $projectId)
            ->first();

        if (! $route || ! $route->modelConfiguration?->is_active) {
            return null;
        }

        $fallback = $route->fallback?->is_active ? $route->fallback : null;

        return ['model' => $route->modelConfiguration, 'fallback' => $fallback];
    }

    /** Replace the route set for a scope (null = global). Empty/zero model ids clear the override. */
    public function sync(?int $projectId, array $map, array $fallbacks = []): void
    {
        foreach (FactoryStep::cases() as $step) {
            $modelId = $map[$step->value] ?? null;

            if (! $modelId) {
                ModelRoute::where('project_id', $projectId)->where('step', $step->value)->delete();

                continue;
            }

            ModelRoute::updateOrCreate(
                ['project_id' => $projectId, 'step' => $step->value],
                [
                    'model_configuration_id' => $modelId,
                    'fallback_model_configuration_id' => $fallbacks[$step->value] ?? null,
                ],
            );
        }
    }
}
