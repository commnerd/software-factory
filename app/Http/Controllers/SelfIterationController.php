<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessSelfProposalJob;
use App\Models\SelfProposal;
use App\Services\SelfIteration\SelfIterationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SelfIterationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SelfIteration/Index', [
            'proposals' => SelfProposal::with('gates')->latest()->limit(30)->get()->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'rationale' => $p->rationale, 'plan' => $p->plan,
                'status' => $p->status, 'test_status' => $p->test_status, 'test_output' => $p->test_output,
                'last_error' => $p->last_error, 'staged_path' => $p->staged_path,
                'files' => collect($p->changes ?? [])->pluck('path'),
                'has_pending_gate' => $p->gates->contains('status', 'pending'),
                'at' => $p->created_at->diffForHumans(),
            ]),
        ]);
    }

    public function analyze(Request $request, SelfIterationService $self): RedirectResponse
    {
        $data = $request->validate(['focus' => ['nullable', 'string', 'max:500']]);

        try {
            $found = $self->analyze($data['focus'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', 'Analysis failed: '.$e->getMessage());
        }

        return back()->with('success', count($found).' proposal(s) added.');
    }

    /** Implement + test a proposal in the background; the result lands as an approval gate. */
    public function process(SelfProposal $proposal): RedirectResponse
    {
        if (! in_array($proposal->status, ['proposed', 'failed'], true)) {
            return back()->with('error', 'This proposal is already in progress or finished.');
        }

        $proposal->update(['status' => 'implementing', 'last_error' => null]);
        ProcessSelfProposalJob::dispatch($proposal->id);

        return back()->with('success', 'Implementation and isolated test run queued.');
    }

    public function destroy(SelfProposal $proposal): RedirectResponse
    {
        $proposal->delete();

        return back()->with('success', 'Proposal dismissed.');
    }
}
