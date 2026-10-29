<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\FactoryStep;
use App\Enums\SdlcStage;
use App\Exceptions\FactoryException;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Ai\AgentRunner;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\StageResult;

class TaskBreakdownStage implements StageHandler
{
    public function __construct(private readonly AgentRunner $agents) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $plan = null;
        $feedback = '';

        for ($attempt = 1; $attempt <= 2 && ! $plan; $attempt++) {
            $run = $this->agents->run(FactoryStep::TaskBreakdown, [
                'project' => ProjectContext::basic($project),
                'requirements' => ProjectContext::stageOutput($project, SdlcStage::Requirements),
                'architecture' => ProjectContext::stageOutput($project, SdlcStage::Architecture),
                'feedback' => $feedback,
                'existing_state' => ProjectContext::existingState($project),
            ], $project);

            $json = $run->json();
            $plan = isset($json['sprints']) && is_array($json['sprints']) ? $json['sprints'] : null;
            $feedback = 'Your previous reply was not valid JSON of the form {"sprints":[{"name","goal","tasks":[...]}]}. Reply with JSON only.';
        }

        if (! $plan) {
            throw new FactoryException('Task breakdown did not return a valid sprint plan.');
        }

        // Re-running replaces the plan, but never discards completed work.
        $project->tasks()->where('status', '!=', 'done')->delete();
        $project->sprints()->whereDoesntHave('tasks')->delete();
        $position = (int) $project->tasks()->max('position');

        foreach (array_values($plan) as $i => $sprintData) {
            $sprint = $project->sprints()->create([
                'name' => $sprintData['name'] ?? 'Sprint '.($i + 1),
                'goal' => $sprintData['goal'] ?? null,
                'position' => $i,
            ]);

            foreach ($sprintData['tasks'] ?? [] as $taskData) {
                $project->tasks()->create([
                    'sprint_id' => $sprint->id,
                    'title' => $taskData['title'] ?? 'Untitled task',
                    'type' => ($taskData['type'] ?? 'feature') === 'characterization' ? 'characterization' : 'feature',
                    'description' => $taskData['description'] ?? null,
                    'acceptance_criteria' => $taskData['acceptance_criteria'] ?? [],
                    'files' => $taskData['files'] ?? [],
                    'position' => ++$position,
                ]);
            }
        }

        $record->update(['output' => json_encode($plan, JSON_PRETTY_PRINT)]);

        return StageResult::completed();
    }
}
