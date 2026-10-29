<?php

namespace App\Http\Controllers;

use App\Models\ImprovementProposal;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Human curation of a project's improvement backlog. */
class ImprovementProposalController extends Controller
{
    /** Add your own idea; it competes with the AI's proposals at the next Backlog Review. */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'category' => ['required', Rule::in(ImprovementProposal::CATEGORIES)],
            'summary' => ['nullable', 'string', 'max:5000'],
            'impact' => ['required', 'integer', 'between:1,5'],
            'effort' => ['required', 'integer', 'between:1,5'],
        ]);

        $project->proposals()->create($data + ['cycle' => max(1, $project->cycle), 'source' => 'user']);

        return back()->with('success', 'Idea added to the backlog.');
    }

    /** dismiss (never propose again) | restore (back on the table) | defer */
    public function update(Request $request, ImprovementProposal $proposal): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['dismiss', 'restore', 'defer'])]])['action'];

        if (in_array($proposal->status, ['approved', 'in_progress', 'done'], true)) {
            return back()->with('error', 'This proposal is being built or already shipped.');
        }

        $proposal->update(['status' => match ($action) {
            'dismiss' => 'rejected',
            'defer' => 'deferred',
            'restore' => 'proposed',
        }]);

        return back()->with('success', 'Backlog updated.');
    }
}
