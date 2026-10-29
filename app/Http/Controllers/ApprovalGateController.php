<?php

namespace App\Http\Controllers;

use App\Models\ApprovalGate;
use App\Services\Pipeline\GateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalGateController extends Controller
{
    public function index(): Response
    {
        $map = fn (ApprovalGate $g) => [
            'id' => $g->id, 'type' => $g->type->value, 'type_label' => $g->type->label(), 'title' => $g->title,
            'summary' => $g->summary, 'detail' => $g->detail, 'payload' => $g->payload ?? [], 'status' => $g->status,
            'decision_note' => $g->decision_note, 'decided_by' => $g->decided_by,
            'project' => $g->project ? ['id' => $g->project->id, 'name' => $g->project->name] : null,
            'task' => $g->task?->title, 'proposal_id' => $g->self_proposal_id,
            'at' => $g->created_at->diffForHumans(), 'decided_at' => $g->decided_at?->diffForHumans(),
        ];

        return Inertia::render('Approvals/Index', [
            'pending' => ApprovalGate::with(['project:id,name', 'task:id,title'])->where('status', 'pending')->oldest()->get()->map($map),
            'history' => ApprovalGate::with(['project:id,name', 'task:id,title'])->where('status', '!=', 'pending')->latest('decided_at')->limit(25)->get()->map($map),
        ]);
    }

    public function decide(Request $request, ApprovalGate $gate, GateService $gates): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
            'new_budget' => ['nullable', 'numeric', 'min:0'],
            'selected_ids' => ['nullable', 'array'],
            'selected_ids.*' => ['integer'],
        ]);

        if (! $gate->isPending()) {
            return back()->with('error', 'This gate was already decided.');
        }

        $gates->decide(
            $gate,
            $data['decision'] === 'approve',
            $data['note'] ?? null,
            'dashboard',
            array_filter(['new_budget' => $data['new_budget'] ?? null, 'selected_ids' => $data['selected_ids'] ?? null], fn ($v) => $v !== null),
        );

        return back()->with('success', $data['decision'] === 'approve' ? 'Approved — execution resumes.' : 'Rejected.');
    }
}
