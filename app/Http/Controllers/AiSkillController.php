<?php

namespace App\Http\Controllers;

use App\Enums\FactoryStep;
use App\Models\AiSkill;
use App\Models\AiSkillVersion;
use App\Models\ModelConfiguration;
use App\Services\Ai\AgentRunner;
use App\Services\Ai\SkillCompiler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class AiSkillController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Skills/Index', [
            'skills' => AiSkill::with('currentVersion:id,version,updated_at')->withCount('versions')->orderBy('kind')->orderBy('step')->orderBy('name')->get()->map(fn ($s) => [
                'id' => $s->id, 'slug' => $s->slug, 'name' => $s->name, 'description' => $s->description,
                'kind' => $s->kind, 'step' => $s->step, 'is_active' => $s->is_active,
                'version' => $s->currentVersion?->version, 'versions_count' => $s->versions_count,
                'updated_at' => $s->currentVersion?->updated_at?->diffForHumans(),
            ]),
            'steps' => FactoryStep::options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Skills/Edit', [
            'skill' => null, 'versions' => [], 'steps' => FactoryStep::options(), 'models' => $this->models(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'alpha_dash', 'max:80', 'unique:ai_skills,slug'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'kind' => ['required', Rule::in(['agent', 'snippet'])],
            'step' => ['nullable', 'required_if:kind,agent', Rule::enum(FactoryStep::class)],
            ...$this->versionRules(),
        ]);

        $skill = AiSkill::create([
            'slug' => $data['slug'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'kind' => $data['kind'], 'step' => $data['kind'] === 'agent' ? $data['step'] : null,
        ]);
        $skill->publish($this->versionData($data) + ['change_note' => 'Initial version']);

        return redirect()->route('skills.show', $skill)->with('success', 'Skill created.');
    }

    public function show(AiSkill $skill, SkillCompiler $compiler): Response
    {
        return Inertia::render('Skills/Edit', [
            'skill' => [
                'id' => $skill->id, 'slug' => $skill->slug, 'name' => $skill->name, 'description' => $skill->description,
                'kind' => $skill->kind, 'step' => $skill->step, 'is_active' => $skill->is_active,
                'current_version_id' => $skill->current_version_id,
                'current' => $skill->currentVersion?->only(['id', 'version', 'system_prompt', 'user_template', 'output_format', 'variables']),
            ],
            'versions' => $skill->versions->map(fn ($v) => [
                'id' => $v->id, 'version' => $v->version, 'change_note' => $v->change_note, 'created_by' => $v->created_by,
                'created_at' => $v->created_at->diffForHumans(), 'system_prompt' => $v->system_prompt,
                'user_template' => $v->user_template, 'output_format' => $v->output_format, 'variables' => $v->variables ?? [],
            ]),
            'steps' => FactoryStep::options(),
            'models' => $this->models(),
            'snippets' => AiSkill::where('kind', 'snippet')->pluck('slug'),
        ]);
    }

    /** Editing never mutates history: it publishes a new version (and makes it current). */
    public function update(Request $request, AiSkill $skill): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            ...$this->versionRules(),
            'change_note' => ['nullable', 'string', 'max:255'],
        ]);

        $skill->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
        $version = $skill->publish($this->versionData($data) + ['change_note' => $data['change_note'] ?? null, 'created_by' => 'dashboard']);

        return back()->with('success', "Published v{$version->version}.");
    }

    public function toggle(AiSkill $skill): RedirectResponse
    {
        $skill->update(['is_active' => ! $skill->is_active]);

        return back()->with('success', $skill->is_active ? 'Skill enabled.' : 'Skill disabled — steps bound to it will fail until another skill is active.');
    }

    public function activate(AiSkill $skill, AiSkillVersion $version): RedirectResponse
    {
        $skill->activate($version);

        return back()->with('success', "v{$version->version} is now live.");
    }

    /** Playground: compile + run a (possibly unsaved) prompt against any model with sample variables. */
    public function test(Request $request, AiSkill $skill, AgentRunner $runner, SkillCompiler $compiler): RedirectResponse
    {
        $data = $request->validate([
            ...$this->versionRules(),
            'model_id' => ['required', 'exists:model_configurations,id'],
            'variables' => ['nullable', 'string'],
            'dry_run' => ['boolean'],
        ]);

        $vars = $data['variables'] ? json_decode($data['variables'], true) : [];
        if (! is_array($vars)) {
            return back()->with('error', 'Sample variables must be valid JSON.');
        }

        // An in-memory (unsaved) version so edits can be tried before publishing.
        $draft = new AiSkillVersion($this->versionData($data));
        $draft->id = $skill->current_version_id;
        $draft->ai_skill_id = $skill->id;

        $compiled = $compiler->compile($draft, $vars);
        $result = ['system' => $compiled->system, 'prompt' => $compiled->user, 'missing' => $compiled->missing];

        if (! ($data['dry_run'] ?? false)) {
            try {
                $run = $runner->test($skill, $draft, ModelConfiguration::findOrFail($data['model_id']), $vars);
                $result += [
                    'response' => $run->response, 'thoughts' => $run->thoughts, 'tokens' => $run->input_tokens + $run->output_tokens,
                    'cost' => (float) $run->cost_usd, 'duration_ms' => $run->duration_ms,
                    'json_valid' => $draft->output_format === 'json' ? $run->json() !== null : null,
                ];
            } catch (Throwable $e) {
                $result['error'] = $e->getMessage();
            }
        }

        return back()->with('skill_test', $result);
    }

    private function versionRules(): array
    {
        return [
            'system_prompt' => ['nullable', 'string', 'max:100000'],
            'user_template' => ['required', 'string', 'max:200000'],
            'output_format' => ['required', Rule::in(['text', 'json'])],
        ];
    }

    private function versionData(array $data): array
    {
        $compiler = app(SkillCompiler::class);
        $vars = $compiler->detectVariables((string) ($data['system_prompt'] ?? ''), $data['user_template']);

        return [
            'system_prompt' => $data['system_prompt'] ?? null,
            'user_template' => $data['user_template'],
            'output_format' => $data['output_format'],
            'variables' => array_map(fn ($v) => ['name' => $v], $vars),
        ];
    }

    private function models()
    {
        return ModelConfiguration::where('is_active', true)->orderBy('tier')->orderBy('name')->get(['id', 'name', 'provider', 'model', 'tier']);
    }
}
