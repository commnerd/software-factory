<?php

namespace App\Http\Controllers;

use App\Enums\FactoryStep;
use App\Enums\SdlcStage;
use App\Exceptions\FactoryException;
use App\Models\ModelConfiguration;
use App\Services\Import\RepositoryImporter;
use App\Models\ModelRoute;
use App\Models\Project;
use App\Services\Ai\ModelRouter;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Projects/Index', [
            'projects' => Project::latest()->get()->map(fn (Project $p) => [
                'id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'language' => $p->language,
                'framework' => $p->framework, 'status' => $p->status, 'current_stage' => $p->current_stage->value,
                'spent_usd' => (float) $p->spent_usd, 'budget_limit_usd' => (float) $p->budget_limit_usd,
                'updated_at' => $p->updated_at->diffForHumans(),
                'lifecycle' => $p->lifecycle->value, 'cycle' => $p->cycle,
                'stages' => $this->stageOptions($p),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Projects/Create', [
            'languages' => collect(config('factory.languages'))->map(fn ($l, $key) => ['value' => $key, 'label' => $l['label'], 'image' => $l['image'], 'test' => $l['test']])->values(),
            'defaultBudget' => config('factory.default_budget_usd'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            // With a repository the brief is optional: the code itself says what exists.
            'brief' => ['required_without:repository_url', 'nullable', 'string', 'max:20000'],
            'language' => ['required', Rule::in([...array_keys(config('factory.languages')), 'auto'])],
            'framework' => ['nullable', 'string', 'max:120'],
            'repository_url' => ['nullable', 'string', 'max:255', $this->sourceRule()],
            'repository_branch' => ['nullable', 'string', 'max:120', 'regex:/^[\w.\/\-]+$/'],
            'budget_limit_usd' => ['nullable', 'numeric', 'min:0'],
            'start' => ['boolean'],
        ]);

        if ($data['language'] === 'auto' && blank($data['repository_url'] ?? null)) {
            return back()->withErrors(['language' => 'Auto-detect needs a repository to look at.'])->withInput();
        }

        $project = Project::create([
            ...collect($data)->except('start')->all(),
            'description' => str($data['brief'] ?? "Existing repository: {$data['repository_url']}")->limit(200)->toString(),
        ]);

        if ($data['start'] ?? false) {
            app(PipelineOrchestrator::class)->start($project);
        }

        return redirect()->route('projects.show', $project)->with('success', 'Project created.');
    }

    private function sandboxRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if ($problem = \App\Support\SandboxCommand::problem((string) $value)) {
                $fail($problem);
            }
        };
    }

    private function sourceRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            try {
                app(RepositoryImporter::class)->assertSourceAllowed((string) $value);
            } catch (FactoryException $e) {
                $fail($e->getMessage());
            }
        };
    }

    public function show(Project $project, ModelRouter $router): Response
    {
        $project->load('stages');

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id, 'name' => $project->name, 'slug' => $project->slug, 'description' => $project->description,
                'brief' => $project->brief, 'language' => $project->language, 'framework' => $project->framework,
                'repository_url' => $project->repository_url, 'existing_code' => $project->existing_code, 'status' => $project->status,
                'current_stage' => $project->current_stage->value, 'last_error' => $project->last_error,
                'budget_limit_usd' => (float) $project->budget_limit_usd, 'spent_usd' => (float) $project->spent_usd,
                'config' => $project->config ?? [], 'profile' => $project->profile(),
                'lifecycle' => $project->lifecycle->value, 'lifecycle_label' => $project->lifecycle->label(),
                'cycle' => $project->cycle, 'cycle_interval_days' => $project->cycle_interval_days,
                'last_cycle_completed_at' => $project->last_cycle_completed_at?->diffForHumans(),
            ],
            'proposals' => $project->proposals()->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'approved' THEN 1 WHEN 'proposed' THEN 2 WHEN 'deferred' THEN 3 WHEN 'failed' THEN 4 WHEN 'done' THEN 5 ELSE 6 END")
                ->orderByDesc('score')->get()->map(fn ($p) => [
                    'id' => $p->id, 'title' => $p->title, 'category' => $p->category, 'summary' => $p->summary,
                    'rationale' => $p->rationale, 'impact' => $p->impact, 'effort' => $p->effort, 'score' => $p->score,
                    'status' => $p->status, 'source' => $p->source, 'cycle' => $p->cycle, 'built_in_cycle' => $p->built_in_cycle,
                    'outcome' => $p->outcome, 'acceptance_criteria' => $p->acceptance_criteria ?? [],
                ]),
            'cycles' => $project->stages->where('stage', SdlcStage::Retrospective)->sortByDesc('cycle')->map(fn ($s) => [
                'cycle' => $s->cycle, 'status' => $s->status, 'retrospective' => $s->output,
                'shipped' => $project->proposals()->where('built_in_cycle', $s->cycle)->pluck('title'),
            ])->values(),
            'stages' => $this->stageOptions($project)->map(function ($s) use ($project) {
                $record = $project->stages->first(fn ($r) => $r->stage->value === $s['value'] && $r->cycle === $project->cycle);

                return $s + [
                    'status' => $record?->status ?? 'pending',
                    'output' => $record?->output,
                    'feedback' => $record?->feedback,
                    'runs' => $record?->runs ?? 0,
                ];
            })->values(),
            'sprints' => $project->sprints()->with('tasks')->get()->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'goal' => $s->goal, 'status' => $s->status, 'cycle' => $s->cycle,
                'tasks' => $s->tasks->map(fn ($t) => [
                    'id' => $t->id, 'title' => $t->title, 'description' => $t->description,
                    'acceptance_criteria' => $t->acceptance_criteria ?? [], 'status' => $t->status,
                    'tdd_phase' => $t->tdd_phase->value, 'type' => $t->type, 'attempts' => $t->attempts, 'notes' => $t->notes,
                ]),
            ]),
            'testSuites' => $project->testSuites()->with('task:id,title')->latest('id')->limit(40)->get()->map(fn ($t) => [
                'id' => $t->id, 'phase' => $t->phase, 'status' => $t->status, 'task' => $t->task?->title,
                'total' => $t->total, 'passed' => $t->passed, 'failed' => $t->failed, 'duration_ms' => $t->duration_ms,
                'image' => $t->image, 'command' => $t->command, 'output' => $t->output, 'at' => $t->created_at->diffForHumans(),
            ]),
            'agentRuns' => $project->agentRuns()->with(['modelConfiguration:id,name', 'skill:id,name', 'skillVersion:id,version', 'task:id,title'])->latest('id')->limit(60)->get()->map(fn ($r) => [
                'id' => $r->id, 'step' => $r->step, 'status' => $r->status, 'task' => $r->task?->title,
                'model' => $r->modelConfiguration?->name, 'skill' => $r->skill?->name, 'skill_version' => $r->skillVersion?->version,
                'tokens' => $r->input_tokens + $r->output_tokens, 'cost' => (float) $r->cost_usd,
                'duration_ms' => $r->duration_ms, 'has_thoughts' => (bool) $r->thoughts, 'error' => $r->error, 'at' => $r->created_at->diffForHumans(),
            ]),
            'gates' => $project->gates()->with('task:id,title')->latest('id')->limit(20)->get()->map(fn ($g) => $this->gatePayload($g)),
            'files' => $project->artifacts()->orderBy('path')->get(['id', 'path', 'kind', 'revision', 'content'])->map(fn ($f) => [
                'id' => $f->id, 'path' => $f->path, 'kind' => $f->kind, 'revision' => $f->revision, 'content' => str($f->content)->limit(30000)->toString(),
            ]),
            'steps' => FactoryStep::options(),
            'models' => $this->modelOptions(),
            'globalRoutes' => ModelRoute::whereNull('project_id')->pluck('model_configuration_id', 'step'),
            'projectRoutes' => $project->modelRoutes()->pluck('model_configuration_id', 'step'),
            'effectiveRoutes' => collect(FactoryStep::cases())->mapWithKeys(fn ($s) => [$s->value => rescue(fn () => $router->route($s, $project), null, false)])
                ->map(fn ($r) => $r ? ['model' => $r['model']->name, 'source' => $r['source']] : null),
        ]);
    }

    public function start(Project $project, PipelineOrchestrator $pipeline): RedirectResponse
    {
        if (in_array($project->status, ['running', 'awaiting_approval'], true)) {
            return back()->with('error', 'The pipeline is already active.');
        }

        if ($project->status === 'idle') {
            return $this->startCycle($project, $pipeline);
        }

        $pipeline->start($project);

        return back()->with('success', 'Pipeline queued. Make sure a queue worker is running.');
    }

    /** Begin the next continuous-improvement cycle (project must be idle). */
    public function startCycle(Project $project, PipelineOrchestrator $pipeline): RedirectResponse
    {
        try {
            $pipeline->startCycle($project);
        } catch (FactoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Improvement cycle {$project->cycle} started: surveying the code for proposals.");
    }

    public function pause(Project $project, PipelineOrchestrator $pipeline): RedirectResponse
    {
        $pipeline->pause($project, 'Paused by user.');

        return back()->with('success', 'Pipeline paused (the current step finishes first).');
    }

    public function settings(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'budget_limit_usd' => ['nullable', 'numeric', 'min:0'],
            'repository_url' => ['nullable', 'string', 'max:255', $this->sourceRule()],
            'cycle_interval_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'config.image' => ['nullable', 'string', 'max:200'],
            'config.install' => ['nullable', 'string', 'max:500', $this->sandboxRule()],
            'config.test' => ['nullable', 'string', 'max:500', $this->sandboxRule()],
            'config.test_dir' => ['nullable', 'string', 'max:200'],
        ]);

        $project->update([
            'budget_limit_usd' => $data['budget_limit_usd'] ?? null,
            'repository_url' => $data['repository_url'] ?? null,
            'cycle_interval_days' => ($data['cycle_interval_days'] ?? 0) ?: null,
            'config' => array_filter($data['config'] ?? [], fn ($v) => filled($v)),
        ]);

        return back()->with('success', 'Project settings saved.');
    }

    /** Per-project model overrides (empty = inherit the global route). */
    public function routes(Request $request, Project $project, ModelRouter $router): RedirectResponse
    {
        $request->validate(['routes' => ['array'], 'routes.*' => ['nullable', 'exists:model_configurations,id']]);

        $router->sync($project->id, $request->input('routes', []));

        return back()->with('success', 'Model overrides saved.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'Project deleted.');
    }

    /** The stages of the project's current lifecycle (a new build, or an improvement cycle). */
    private function stageOptions(Project $project)
    {
        return collect($project->sequence())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label($project->lifecycle)]);
    }

    private function modelOptions()
    {
        return ModelConfiguration::where('is_active', true)->orderBy('tier')->orderBy('name')->get(['id', 'name', 'provider', 'model', 'tier']);
    }

    private function gatePayload($g): array
    {
        return [
            'id' => $g->id, 'type' => $g->type->value, 'type_label' => $g->type->label(), 'title' => $g->title,
            'summary' => $g->summary, 'detail' => $g->detail, 'payload' => $g->payload ?? [], 'status' => $g->status,
            'decision_note' => $g->decision_note, 'task' => $g->task?->title, 'project' => null,
            'at' => $g->created_at->diffForHumans(), 'decided_at' => $g->decided_at?->diffForHumans(),
        ];
    }
}
