<?php

namespace App\Services\Pipeline;

use App\Enums\SdlcStage;
use App\Models\Project;
use App\Models\TestSuite;

/**
 * Turns the AI's judgement (plus hard facts from the import) into a plan:
 * which SDLC stages are already satisfied by the repository, and where to resume.
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
            'summary' => (string) ($ai['summary'] ?? 'Existing repository imported; AI assessment was unavailable so the factory will rebuild missing documents conservatively.'),
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
     * The first stage that still has work to do.
     *  - requirements / architecture documents missing -> (re)produce them, using the code as input
     *  - gap tasks listed -> go straight to TDD for just those
     *  - nothing left to build -> QA
     *  - otherwise let the task breakdown plan the remaining delta
     */
    public function startStage(array $plan): SdlcStage
    {
        $s = $plan['stages'];

        return match (true) {
            $s['requirements']['status'] !== 'complete' => SdlcStage::Requirements,
            $s['architecture']['status'] !== 'complete' => SdlcStage::Architecture,
            $plan['tasks'] !== [] => SdlcStage::TddImplementation,
            $s['implementation']['status'] === 'complete' => SdlcStage::Qa,
            default => SdlcStage::TaskBreakdown,
        };
    }

    /** Mark everything before $start as satisfied, import usable documents, create gap tasks. */
    public function apply(Project $project, ?SdlcStage $override = null): SdlcStage
    {
        $plan = $project->assessment;
        $start = $override ?? $this->startStage($plan);
        $order = array_map(fn (SdlcStage $s) => $s->value, SdlcStage::cases());
        $startIndex = array_search($start->value, $order, true);

        $documents = [
            SdlcStage::Requirements->value => $plan['stages']['requirements']['document'] ?? null,
            SdlcStage::Architecture->value => $plan['stages']['architecture']['document'] ?? null,
        ];

        foreach (SdlcStage::cases() as $i => $stage) {
            $record = $project->stage($stage);

            if ($i < $startIndex) {
                $record->markCompleted($documents[$stage->value] ?? match ($stage) {
                    SdlcStage::Assessment => $record->output,
                    SdlcStage::TaskBreakdown => 'Skipped — remaining work was taken directly from the assessment.',
                    SdlcStage::TddImplementation => 'Skipped — nothing left to implement according to the assessment.',
                    default => 'Skipped — already satisfied by the existing repository.',
                });
            }
        }

        // Gap tasks only matter if we are about to implement them.
        if ($start === SdlcStage::TddImplementation && $plan['tasks'] !== [] && $project->tasks()->count() === 0) {
            $sprint = $project->sprints()->create([
                'name' => 'Existing codebase — remaining work',
                'goal' => 'Close the gaps found when assessing the imported repository.',
                'position' => 0,
            ]);

            foreach ($plan['tasks'] as $i => $t) {
                $project->tasks()->create($t + ['sprint_id' => $sprint->id, 'position' => $i + 1]);
            }
        }

        $project->update(['current_stage' => $start]);

        return $start;
    }

    public function summaryMarkdown(array $plan, array $inventory): string
    {
        $lines = ["# Assessment of the existing repository", '', $plan['summary'], ''];

        $lines[] = sprintf('**Detected:** %s%s · **Files:** %d · **Tests found:** %d · **CI workflows:** %d',
            $plan['detected']['language'] ?? 'unknown',
            $plan['detected']['framework'] ? ' / '.$plan['detected']['framework'] : '',
            $inventory['file_count'], $inventory['test_file_count'], count($inventory['ci_workflows']));

        if ($plan['baseline']) {
            $b = $plan['baseline'];
            $lines[] = "**Baseline test run:** {$b['status']} ({$b['passed']}/{$b['total']} passed)";
        }

        $lines[] = '';
        $lines[] = '## Stage status';
        foreach ($plan['stages'] as $key => $s) {
            $lines[] = sprintf('- **%s**: %s%s', $key, $s['status'], ($s['evidence'] ?? '') !== '' ? ' — '.$s['evidence'] : '');
        }

        if ($plan['tasks']) {
            $lines[] = '';
            $lines[] = '## Remaining work';
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
        $lines[] = '**Recommended starting point:** '.$this->startStage($plan)->label();

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
