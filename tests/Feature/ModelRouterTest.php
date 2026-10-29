<?php

namespace Tests\Feature;

use App\Enums\FactoryStep;
use App\Models\ModelConfiguration;
use App\Models\Project;
use App\Services\Ai\ModelRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelRouterTest extends TestCase
{
    use RefreshDatabase;

    private function model(string $name, string $tier, bool $active = true, bool $default = false): ModelConfiguration
    {
        return ModelConfiguration::create([
            'name' => $name, 'provider' => 'fake', 'model' => $name, 'tier' => $tier, 'is_active' => $active, 'is_default' => $default,
        ]);
    }

    public function test_project_route_beats_global_route_beats_tier_beats_default(): void
    {
        $router = app(ModelRouter::class);
        $project = Project::create(['name' => 'P', 'language' => 'php']);
        $step = FactoryStep::TddGreen; // frontier by default

        $default = $this->model('default', 'cheap', default: true);
        $this->assertSame($default->id, $router->resolve($step, $project)->id);
        $this->assertSame('default', $router->route($step, $project)['source']);

        $frontier = $this->model('frontier', 'frontier');
        $this->assertSame($frontier->id, $router->resolve($step, $project)->id);
        $this->assertSame('tier', $router->route($step, $project)['source']);

        $global = $this->model('global', 'standard');
        $router->sync(null, [$step->value => $global->id]);
        $this->assertSame($global->id, $router->resolve($step, $project)->id);

        $override = $this->model('override', 'cheap');
        $router->sync($project->id, [$step->value => $override->id]);
        $this->assertSame($override->id, $router->resolve($step, $project)->id);
        $this->assertSame('project', $router->route($step, $project)['source']);

        // other projects are unaffected by the override
        $other = Project::create(['name' => 'Q', 'language' => 'php']);
        $this->assertSame($global->id, $router->resolve($step, $other)->id);
    }

    public function test_inactive_models_are_skipped(): void
    {
        $router = app(ModelRouter::class);
        $off = $this->model('off', 'frontier', active: false);
        $on = $this->model('on', 'standard', default: true);
        $router->sync(null, [FactoryStep::TddGreen->value => $off->id]);

        $this->assertSame($on->id, $router->resolve(FactoryStep::TddGreen)->id);
    }

    public function test_clearing_a_route_removes_the_override(): void
    {
        $router = app(ModelRouter::class);
        $project = Project::create(['name' => 'P', 'language' => 'php']);
        $a = $this->model('a', 'standard', default: true);
        $b = $this->model('b', 'standard');

        $router->sync($project->id, [FactoryStep::QaReview->value => $b->id]);
        $router->sync($project->id, [FactoryStep::QaReview->value => '']);

        $this->assertSame(0, $project->modelRoutes()->count());
        $this->assertSame($a->id, $router->resolve(FactoryStep::QaReview, $project)->id);
    }

    public function test_it_fails_loudly_when_no_model_exists(): void
    {
        $this->expectException(\App\Exceptions\FactoryException::class);
        app(ModelRouter::class)->resolve(FactoryStep::QaReview);
    }
}
