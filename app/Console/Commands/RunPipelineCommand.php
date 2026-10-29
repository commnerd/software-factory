<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Console\Command;

class RunPipelineCommand extends Command
{
    protected $signature = 'factory:run {project : Project id or slug}';

    protected $description = 'Run a project\'s pipeline synchronously (debugging; the dashboard uses the queue)';

    public function handle(PipelineOrchestrator $pipeline): int
    {
        $project = Project::where('id', $this->argument('project'))->orWhere('slug', $this->argument('project'))->firstOrFail();

        $project->update(['status' => 'running']);
        $pipeline->advance($project);
        $project->refresh();

        $this->info("Status: {$project->status} · stage: {$project->current_stage->label()}");
        if ($project->last_error) {
            $this->error($project->last_error);
        }

        return $project->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
