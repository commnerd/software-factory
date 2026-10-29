<?php

namespace App\Http\Controllers;

use App\Enums\FactoryStep;
use App\Enums\ModelTier;
use App\Models\ModelConfiguration;
use App\Models\ModelRoute;
use App\Services\Ai\ModelRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ModelConfigurationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Models/Index', [
            'models' => ModelConfiguration::orderBy('tier')->orderBy('name')->get()->map(fn ($m) => [
                'id' => $m->id, 'name' => $m->name, 'provider' => $m->provider, 'model' => $m->model, 'tier' => $m->tier->value,
                'parameters' => $m->parameters ?? [], 'input_cost_per_mtok' => $m->input_cost_per_mtok,
                'output_cost_per_mtok' => $m->output_cost_per_mtok, 'is_active' => $m->is_active, 'is_default' => $m->is_default,
                'in_use' => $m->routes()->count(),
            ]),
            'steps' => FactoryStep::options(),
            'routes' => ModelRoute::whereNull('project_id')->get(['step', 'model_configuration_id', 'fallback_model_configuration_id'])->keyBy('step'),
            'providers' => array_keys(config('factory.providers')),
            'tiers' => array_map(fn ($t) => $t->value, ModelTier::cases()),
            'providerStatus' => collect(config('factory.providers'))->map(fn ($c, $name) => match (true) {
                $name === 'fake', $name === 'ollama' => 'ready',
                default => ! empty($c['key']) ? 'ready' : 'missing key',
            }),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ModelConfiguration::create($this->validated($request));

        return back()->with('success', 'Model added.');
    }

    public function update(Request $request, ModelConfiguration $model): RedirectResponse
    {
        $data = $this->validated($request);
        $model->update($data);

        if ($data['is_default'] ?? false) {
            ModelConfiguration::where('id', '!=', $model->id)->update(['is_default' => false]);
        }

        return back()->with('success', 'Model updated.');
    }

    public function destroy(ModelConfiguration $model): RedirectResponse
    {
        $model->delete();

        return back()->with('success', 'Model removed (routes using it were cleared).');
    }

    /** Global step → model matrix. */
    public function routing(Request $request, ModelRouter $router): RedirectResponse
    {
        $request->validate([
            'routes' => ['array'], 'routes.*' => ['nullable', 'exists:model_configurations,id'],
            'fallbacks' => ['array'], 'fallbacks.*' => ['nullable', 'exists:model_configurations,id'],
        ]);

        $router->sync(null, $request->input('routes', []), array_filter($request->input('fallbacks', [])));

        return back()->with('success', 'Routing saved.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['required', Rule::in(array_keys(config('factory.providers')))],
            'model' => ['required', 'string', 'max:160'],
            'tier' => ['required', Rule::enum(ModelTier::class)],
            'input_cost_per_mtok' => ['required', 'numeric', 'min:0'],
            'output_cost_per_mtok' => ['required', 'numeric', 'min:0'],
            'parameters' => ['nullable', 'array'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ]);

        $data['parameters'] = array_map(fn ($v) => is_numeric($v) ? $v + 0 : $v, array_filter($data['parameters'] ?? [], fn ($v) => $v !== null && $v !== ''));

        return $data;
    }
}
