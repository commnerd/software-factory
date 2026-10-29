<?php

namespace App\Http\Controllers;

use App\Models\AgentRun;
use Inertia\Inertia;
use Inertia\Response;

class AgentRunController extends Controller
{
    public function show(AgentRun $run): Response
    {
        $run->load(['project:id,name', 'task:id,title', 'skill:id,name,slug', 'skillVersion:id,version', 'modelConfiguration:id,name,provider,model']);

        return Inertia::render('AgentRuns/Show', [
            'run' => [
                ...$run->only(['id', 'step', 'status', 'system_prompt', 'prompt', 'thoughts', 'response', 'input_tokens', 'output_tokens', 'duration_ms', 'error', 'is_test']),
                'cost' => (float) $run->cost_usd,
                'project' => $run->project ? ['id' => $run->project->id, 'name' => $run->project->name] : null,
                'task' => $run->task?->title,
                'skill' => $run->skill ? ['id' => $run->skill->id, 'name' => $run->skill->name, 'version' => $run->skillVersion?->version] : null,
                'model' => $run->modelConfiguration?->only(['name', 'provider', 'model']),
                'at' => $run->created_at->toDateTimeString(),
            ],
        ]);
    }
}
