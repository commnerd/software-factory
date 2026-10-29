<?php

namespace App\Services\Pipeline\Stages;

use App\Enums\GateType;
use App\Enums\TddPhase;
use App\Exceptions\TddStuckException;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Pipeline\Contracts\StageHandler;
use App\Services\Pipeline\GateService;
use App\Services\Pipeline\StageResult;
use App\Services\Pipeline\TddEngine;

class ImplementationStage implements StageHandler
{
    public function __construct(
        private readonly TddEngine $tdd,
        private readonly GateService $gates,
    ) {}

    public function run(Project $project, ProjectStage $record): StageResult
    {
        $tasks = $project->cycleTasks()->whereNotIn('tdd_phase', [TddPhase::Done->value, TddPhase::Blocked->value])->get();

        foreach ($tasks as $task) {
            try {
                $this->tdd->implement($project, $task);
            } catch (TddStuckException $e) {
                $task->setPhase(TddPhase::Blocked);

                $this->gates->open(
                    GateType::Intervention,
                    "Task stuck — {$task->title}",
                    $project,
                    $e->getMessage().' Approve to retry the task from scratch; reject to skip it and continue.',
                    detail: $this->lastOutput($task),
                    payload: ['kind' => 'tdd_stuck'],
                    task: $task,
                );

                return StageResult::awaitingApproval();
            }
        }

        $done = $project->cycleTasks()->where('tdd_phase', TddPhase::Done->value)->count();
        $record->update(['output' => "{$done}/{$project->cycleTasks()->count()} tasks completed through red → green → refactor."]);

        return StageResult::completed();
    }

    private function lastOutput($task): ?string
    {
        return $task->testSuites()->latest('id')->first()?->output;
    }
}
