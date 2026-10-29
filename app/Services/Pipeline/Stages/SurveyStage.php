<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\SdlcStage;
use App\Models\ImprovementProposal;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Execution\TestRunner;
use App\Services\Import\RepositoryImporter;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\StageResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Start of every improvement cycle: look at the code as it is today (plus what past cycles learned)
 * and propose what to do next — new features, fixes, missing tests, refactors, security, docs…
 * Proposals only land in the backlog here; a human chooses what gets built in the next stage.
 */
class SurveyStage implements StageHandler
{
    public function __construct(
        private readonly RepositoryImporter $importer,
        private readonly TestRunner $tests,
        private readonly AgentRunner $agents,
    ) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $inventory = $this->importer->refresh($project);
        $health = $this->health($project);

        $run = $this->agents->run(FactoryStep::FeatureSurvey, [
            'project' => ProjectContext::basic($project),
            'cycle' => $project->cycle,
            'vision' => $project->brief ?? $project->description ?? '',
            'knowledge' => [
                'requirements' => mb_substr($project->knowledge(SdlcStage::Requirements), 0, 6000),
                'architecture' => mb_substr($project->knowledge(SdlcStage::Architecture), 0, 6000),
                'assessment' => ProjectContext::existingState($project),
            ],
            'inventory' => $inventory,
            'health' => $health,
            'open_backlog' => $this->listing($project->proposals()->whereIn('status', ImprovementProposal::OPEN)->get()),
            'history' => $this->listing($project->proposals()->whereIn('status', ['done', 'rejected', 'failed'])->latest('id')->limit(40)->get(), withOutcome: true),
            'retrospectives' => $this->retrospectives($project),
            'feedback' => $record->feedback ?? '',
        ], $project);

        $created = $this->store($project, $run->json()['proposals'] ?? []);

        $open = $project->proposals()->whereIn('status', ImprovementProposal::OPEN)->count();
        $record->update([
            'feedback' => null,
            'output' => $this->summary($project, $run->json()['observations'] ?? null, $created, $open),
        ]);

        return StageResult::completed();
    }

    /** @return array<string, mixed> */
    private function health(Project $project): array
    {
        $suite = null;

        if (config('factory.import.run_baseline')) {
            try {
                $suite = $this->tests->run($project, 'baseline');
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'tests' => $suite ? ['status' => $suite->status, 'passed' => $suite->passed, 'total' => $suite->total, 'output_tail' => mb_substr((string) $suite->output, -2500)] : 'not run',
            'blocked_tasks' => $project->tasks()->where('tdd_phase', 'blocked')->pluck('title')->all(),
            'recent_agent_failures' => $project->agentRuns()->where('status', 'failed')->latest('id')->limit(5)->pluck('error')->all(),
        ];
    }

    private function listing($proposals, bool $withOutcome = false): array
    {
        return $proposals->map(fn (ImprovementProposal $p) => array_filter([
            'id' => $p->id, 'title' => $p->title, 'category' => $p->category, 'status' => $p->status,
            'outcome' => $withOutcome ? $p->outcome ?? $p->decision_note : null,
        ], fn ($v) => $v !== null))->values()->all();
    }

    /** What recent cycles concluded, so the survey learns instead of repeating itself. */
    private function retrospectives(Project $project): array
    {
        return $project->stages()->where('stage', SdlcStage::Retrospective->value)->where('status', 'completed')
            ->whereNotNull('output')->orderByDesc('cycle')->limit(2)->get()
            ->map(fn ($s) => ['cycle' => $s->cycle, 'retrospective' => mb_substr((string) $s->output, 0, 3000)])->all();
    }

    /** @return list<ImprovementProposal> */
    private function store(Project $project, array $items): array
    {
        $known = $project->proposals()->get()->map(fn ($p) => $this->key($p->title))->all();
        $created = [];

        foreach ($items as $item) {
            if (! is_array($item) || blank($item['title'] ?? null) || in_array($this->key($item['title']), $known, true)) {
                continue; // blank, or already proposed / built / rejected before
            }

            $category = in_array($item['category'] ?? null, ImprovementProposal::CATEGORIES, true) ? $item['category'] : 'enhancement';

            $created[] = $project->proposals()->create([
                'cycle' => $project->cycle,
                'title' => Str::limit(trim((string) $item['title']), 250, ''),
                'category' => $category,
                'summary' => (string) ($item['summary'] ?? ''),
                'rationale' => (string) ($item['rationale'] ?? ''),
                'evidence' => array_values((array) ($item['evidence'] ?? [])),
                'acceptance_criteria' => array_values((array) ($item['acceptance_criteria'] ?? [])),
                'files' => array_values((array) ($item['files'] ?? [])),
                'impact' => (int) ($item['impact'] ?? 3),
                'effort' => (int) ($item['effort'] ?? 3),
                'source' => 'survey',
            ]);
            $known[] = $this->key($item['title']);
        }

        return $created;
    }

    private function key(string $title): string
    {
        return Str::of($title)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }

    private function summary(Project $project, ?string $observations, array $created, int $open): string
    {
        $lines = ["# Survey — cycle {$project->cycle}", ''];

        if ($observations) {
            $lines[] = $observations;
            $lines[] = '';
        }

        $lines[] = count($created).' new proposal(s); '.$open.' open in the backlog.';
        foreach ($created as $p) {
            $lines[] = "- **{$p->title}** ({$p->category}) — impact {$p->impact}, effort {$p->effort}, score {$p->score}";
        }

        return implode("\n", $lines);
    }
}
