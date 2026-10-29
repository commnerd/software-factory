<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Console\Command;

class CycleDueCommand extends Command
{
    protected $signature = 'factory:cycle-due';

    protected $description = 'Start the next improvement cycle for idle projects whose auto-cycle interval has elapsed';

    public function handle(PipelineOrchestrator $pipeline): int
    {
        $started = 0;

        Project::where('status', 'idle')->whereNotNull('cycle_interval_days')->where('cycle_interval_days', '>', 0)->get()
            ->each(function (Project $project) use ($pipeline, &$started) {
                $since = $project->last_cycle_completed_at ?? $project->updated_at;

                if ($since->copy()->addDays($project->cycle_interval_days)->isFuture()) {
                    return;
                }

                $pipeline->startCycle($project);
                $this->line("Started cycle {$project->cycle} for {$project->name}");
                $started++;
            });

        $this->info("{$started} cycle(s) started.");

        return self::SUCCESS;
    }
}
