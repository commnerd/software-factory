<?php

namespace App\Services\Pipeline;

use App\Enums\SdlcStage;
use App\Models\ImprovementProposal;
use App\Models\Project;
use App\Models\TestSuite;

/**
 * Turns the AI's judgement (plus hard facts from the import) into a plan, then — once a human confirms —
 * seeds the first improvement cycle with what the assessment found.
 *
 * Plan shape (stored in projects.assessment):
 *   summary, detected{language,framework}, risks[], baseline{status,passed,total},
 *   stages{requirements|architecture|implementation|qa|deployment: {status: complete|partial|missing, evidence, document?}},
 *   tasks[{title, description, acceptance_criteria[], files[], type: feature|characterization}]
 */
class AssessmentPlanner
{
    private const STAGE_KEYS = ['requirements', 'architecture', 'implementation', 'qa', 'deployment'];

    /** @param  array<string, mixed>|null  $ai  decoded AI reply (null/invalid => conservative heuristics) */
    public function normalize(?array $ai, array $inventory, ?TestSuite $baseline): array
    {
        $fromAi = is_array($ai) && isset($ai['stages']) && is_array($ai['stages']);
        $stages = [];

        foreach (self::STAGE_KEYS as $key) {
            $raw = $fromAi ? ($ai['stages'][$key] ?? []) : [];
            $status = in_array($raw['status'] ?? null, ['complete', 'partial', 'missing'], true) ? $raw['status'] : $this->guess($key, $inventory);
            $document = trim((string) ($raw['document'] ?? ''));

            // Only trust "complete" for the documents we can actually carry forward.
            if (in_array($key, ['requirements', 'architecture'], true) && $status === 'complete' && $document === '') {
                $status = 'partial';
            }

            $stages[$key] = array_filter([
                'status' => $status,
                'evidence' => (string) ($raw['evidence'] ?? ($fromAi ? '' : 'Heuristic: AI assessment unavailable.')),
                'document' => $document !== '' ? $document : null,
            ], fn ($v) => $v !== null);
        }

        $tasks = [];
        foreach ($fromAi ? ($ai['stages']['task_breakdown']['tasks'] ?? $ai['tasks'] ?? []) : [] as $t) {
            if (! is_array($t) || empty($t['title'])) {
                continue;
            }
            $tasks[] = [
                'title' => (string) $t['title'],
                'description' => (string) ($t['description'] ?? ''),
                'acceptance_criteria' => array_values((array) ($t['acceptance_criteria'] ?? [])),
                'files' => array_values((array) ($t['files'] ?? [])),
                'type' => ($t['type'] ?? 'feature') === 'characterization' ? 'characterization' : 'feature',
            ];
        }

        return [
            'summary' => (string) ($ai['summary'] ?? 'Existing repository imported; AI assessment was unavailable, so the first survey will work from the code alone.'),
            'detected' => [
                'language' => $ai['detected']['language'] ?? $inventory['detected']['language'] ?? null,
                'framework' => $ai['detected']['framework'] ?? $inventory['detected']['framework'] ?? null,
            ],
            'risks' => array_values(array_map('strval', (array) ($ai['risks'] ?? []))),
            'baseline' => $baseline ? ['status' => $baseline->status, 'passed' => $baseline->passed, 'total' => $baseline->total] : null,
            'stages' => $stages,
            'tasks' => $tasks,
            'ai_assessed' => $fromAi,
        ];
    }

    /**
     * Assessment confirmed. Imported code is not a build: carry its documents forward as project
     * knowledge and turn every gap into a backlog proposal for cycle 1 (the survey adds more).
     *
     * @return list<ImprovementProposal>
     */
    public function apply(Project $project): array
    {
        $plan = $project->assessment;

        foreach ([[SdlcStage::Requirements, 'requirements'], [SdlcStage::Architecture, 'architecture']] as [$stage, $key]) {
            if (! empty($plan['stages'][$key]['document'])) {
                $project->stage($stage, 0)->markCompleted($plan['stages'][$key]['document']);
            }
        }
        $project->stage(SdlcStage::Assessment, 0)->markCompleted();

        $seeds = [];

        foreach ($plan['tasks'] as $t) {
            $characterize = $t['type'] === 'characterization';
            $seeds[] = [
                'title' => $t['title'],
                'category' => $characterize ? 'tests' : 'feature',
                'summary' => $t['description'],
                'rationale' => 'Gap found while assessing the imported repository.',
                'acceptance_criteria' => $t['acceptance_criteria'],
                'files' => $t['files'],
                'impact' => $characterize ? 3 : 4,
                'effort' => $characterize ? 2 : 3,
            ];
        }

        $gaps = [
            ['requirements', 'Document the product requirements', 'docs', 'The goals and functional requirements are not written down; recover them from the code.'],
            ['architecture', 'Document the architecture', 'docs', 'No design documentation: describe components, data flow and key decisions.'],
            ['deployment', 'Add a CI pipeline', 'devex', 'No build/test pipeline was found in the repository.'],
        ];
        foreach ($gaps as [$key, $title, $category, $why]) {
            $status = $plan['stages'][$key]['status'] ?? 'complete';

            // Documents must be *complete* to count; a pipeline only has to exist.
            if ($key === 'deployment' ? $status === 'missing' : $status !== 'complete') {
                $seeds[] = ['title' => $title, 'category' => $category, 'summary' => $why, 'rationale' => $why, 'impact' => 3, 'effort' => 1,
                    'acceptance_criteria' => [$title.' is committed and reviewed.'], 'files' => []];
            }
        }

        if (($plan['stages']['qa']['status'] ?? 'missing') === 'missing' && ! collect($seeds)->contains('category', 'tests')) {
            $seeds[] = ['title' => 'Add a baseline automated test suite', 'category' => 'tests', 'summary' => 'No automated tests were found; characterize the most important existing behaviour.',
                'rationale' => 'Without tests nothing else can be changed safely.', 'impact' => 5, 'effort' => 3, 'acceptance_criteria' => ['Tests pass against the current code.'], 'files' => []];
        }

        $known = $project->proposals()->pluck('title')->map(fn ($t) => mb_strtolower($t))->all();
        $created = [];

        foreach ($seeds as $seed) {
            if (in_array(mb_strtolower($seed['title']), $known, true)) {
                continue;
            }
            $created[] = $project->proposals()->create($seed + ['cycle' => 1, 'source' => 'assessment']);
        }

        return $created;
    }

    public function summaryMarkdown(array $plan, array $inventory): string
    {
        $lines = ['# Assessment of the existing repository', '', $plan['summary'], ''];

        $lines[] = sprintf('**Detected:** %s%s · **Files:** %d · **Tests found:** %d · **CI workflows:** %d',
            $plan['detected']['language'] ?? 'unknown',
            $plan['detected']['framework'] ? ' / '.$plan['detected']['framework'] : '',
            $inventory['file_count'], $inventory['test_file_count'], count($inventory['ci_workflows']));

        if ($plan['baseline']) {
            $b = $plan['baseline'];
            $lines[] = "**Baseline test run:** {$b['status']} ({$b['passed']}/{$b['total']} passed)";
        }

        $lines[] = '';
        $lines[] = '## Where it stands';
        foreach ($plan['stages'] as $key => $s) {
            $lines[] = sprintf('- **%s**: %s%s', $key, $s['status'], ($s['evidence'] ?? '') !== '' ? ' — '.$s['evidence'] : '');
        }

        if ($plan['tasks']) {
            $lines[] = '';
            $lines[] = '## Gaps (will seed the first backlog)';
            foreach ($plan['tasks'] as $t) {
                $lines[] = "- [{$t['type']}] {$t['title']}";
            }
        }

        if ($plan['risks']) {
            $lines[] = '';
            $lines[] = '## Risks';
            foreach ($plan['risks'] as $r) {
                $lines[] = "- {$r}";
            }
        }

        $lines[] = '';
        $lines[] = '**Next:** the project enters continuous improvement — the factory surveys the code for features and fixes to propose, and you choose what each cycle builds.';

        return implode("\n", $lines);
    }

    private function guess(string $key, array $inventory): string
    {
        return match ($key) {
            'requirements' => $inventory['has_readme'] ? 'partial' : 'missing',
            'architecture' => $inventory['has_docs'] ? 'partial' : 'missing',
            'implementation' => 'partial',
            'qa' => $inventory['test_file_count'] > 0 ? 'partial' : 'missing',
            'deployment' => $inventory['ci_workflows'] !== [] ? 'partial' : 'missing',
        };
    }
}
