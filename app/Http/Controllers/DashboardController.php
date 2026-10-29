<?php

namespace App\Http\Controllers;

use App\Enums\SdlcStage;
use App\Models\AgentRun;
use App\Models\ApprovalGate;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'projects' => Project::count(),
                'running' => Project::where('status', 'running')->count(),
                'awaiting' => ApprovalGate::where('status', 'pending')->count(),
                'spend' => (float) AgentRun::where('is_test', false)->sum('cost_usd'),
                'runs' => AgentRun::where('is_test', false)->count(),
            ],
            'projects' => Project::latest()->limit(8)->get()->map(fn (Project $p) => [
                'id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'language' => $p->language,
                'lifecycle' => $p->lifecycle->value, 'cycle' => $p->cycle,
                'stages' => collect($p->sequence())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label($p->lifecycle)]),
                'current_stage' => $p->current_stage->value, 'spent_usd' => (float) $p->spent_usd,
            ]),
            'gates' => ApprovalGate::with('project:id,name')->where('status', 'pending')->latest()->limit(5)->get()
                ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title, 'type' => $g->type->value, 'project' => $g->project?->name, 'created_at' => $g->created_at->diffForHumans()]),
            'activity' => AgentRun::with(['project:id,name', 'modelConfiguration:id,name'])->where('is_test', false)->latest()->limit(8)->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'step' => $r->step, 'status' => $r->status, 'project' => $r->project?->name,
                    'model' => $r->modelConfiguration?->name, 'cost' => (float) $r->cost_usd, 'at' => $r->created_at->diffForHumans(),
                ]),
        ]);
    }
}
