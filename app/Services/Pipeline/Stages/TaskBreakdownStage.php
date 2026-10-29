<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\Lifecycle;
use App\Enums\SdlcStage;
use App\Exceptions\FactoryException;
use App\Models\ImprovementProposal;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\StageResult;

/**
 * Build lifecycle: turn requirements + architecture into sprints and tasks.
 * Improve lifecycle ("cycle planning"): turn the proposals a human selected into this cycle's tasks.
 */
class TaskBreakdownStage implements StageHandler
{
    public function __construct(private readonly AgentRunner $agents) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $improving = $project->lifecycle === Lifecycle::Improve;
        $proposals = $improving ? $project->proposals()->where('status', 'approved')->orderByDesc('score')->get() : collect();

        if ($improving && $proposals->isEmpty()) {
            throw new FactoryException('Cycle planning found no approved proposals to plan.');
        }

        $plan = $this->plan($project, $proposals->all());

        // Re-running replaces this cycle's plan, but never discards completed work.
        $project->cycleTasks()->where('status', '!=', 'done')->delete();
        $project->sprints()->where('cycle', $project->cycle)->whereDoesntHave('tasks')->delete();
        $position = (int) $project->tasks()->where('cycle', $project->cycle)->max('position');

        foreach (array_values($plan) as $i => $sprintData) {
            $sprint = $project->sprints()->create([
                'cycle' => $project->cycle,
                'name' => $sprintData['name'] ?? ($improving ? "Cycle {$project->cycle}" : 'Sprint '.($i + 1)),
                'goal' => $sprintData['goal'] ?? null,
                'position' => $i,
            ]);

            foreach ($sprintData['tasks'] ?? [] as $taskData) {
                $linked = $this->linkedProposal($taskData, $proposals);

                $project->tasks()->create([
                    'cycle' => $project->cycle,
                    'sprint_id' => $sprint->id,
                    'improvement_proposal_id' => $linked?->id,
                    'title' => $taskData['title'] ?? 'Untitled task',
                    'type' => ($taskData['type'] ?? $linked?->taskType() ?? 'feature') === 'characterization' ? 'characterization' : 'feature',
                    'description' => $taskData['description'] ?? null,
                    'acceptance_criteria' => $taskData['acceptance_criteria'] ?? [],
                    'files' => $taskData['files'] ?? [],
                    'position' => ++$position,
                ]);
            }
        }

        foreach ($proposals as $proposal) {
            $proposal->update(['status' => 'in_progress']);
        }

        $record->update(['output' => json_encode($plan, JSON_PRETTY_PRINT)]);

        return StageResult::completed();
    }

    /** @param  list<ImprovementProposal>  $proposals */
    private function plan(Project $project, array $proposals): array
    {
        $feedback = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $run = $this->agents->run(FactoryStep::TaskBreakdown, [
                'project' => ProjectContext::basic($project),
                'requirements' => $project->knowledge(SdlcStage::Requirements),
                'architecture' => $project->knowledge(SdlcStage::Architecture),
                'existing_state' => ProjectContext::existingState($project),
                'proposals' => array_map(fn (ImprovementProposal $p) => [
                    'id' => $p->id, 'title' => $p->title, 'category' => $p->category, 'summary' => $p->summary,
                    'acceptance_criteria' => $p->acceptance_criteria ?? [], 'files' => $p->files ?? [],
                ], $proposals),
                'feedback' => $feedback,
            ], $project);

            $json = $run->json();
            if (isset($json['sprints']) && is_array($json['sprints']) && $json['sprints'] !== []) {
                return $json['sprints'];
            }

            $feedback = 'Your previous reply was not valid JSON of the form {"sprints":[{"name","goal","tasks":[...]}]}. Reply with JSON only.';
        }

        if ($proposals === []) {
            throw new FactoryException('Task breakdown did not return a valid sprint plan.');
        }

        // An approved proposal already carries its criteria and files: plan it as one task rather than stall the cycle.
        return [[
            'name' => "Cycle {$project->cycle}",
            'goal' => implode('; ', array_map(fn ($p) => $p->title, $proposals)),
            'tasks' => array_map(fn (ImprovementProposal $p) => [
                'proposal_id' => $p->id, 'title' => $p->title, 'type' => $p->taskType(), 'description' => $p->summary,
                'acceptance_criteria' => $p->acceptance_criteria ?? [], 'files' => $p->files ?? [],
            ], $proposals),
        ]];
    }

    private function linkedProposal(array $taskData, $proposals): ?ImprovementProposal
    {
        if ($proposals->isEmpty()) {
            return null;
        }

        return $proposals->firstWhere('id', (int) ($taskData['proposal_id'] ?? 0))
            ?? ($proposals->count() === 1 ? $proposals->first() : null);
    }
}
