<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Pipeline\PipelineOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class AdvancePipelineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    // Overlap-releases count as attempts, so allow unlimited tries but only one real exception.
    public int $tries = 0;

    public int $maxExceptions = 1;

    public function __construct(public int $projectId) {}

    /** One worker per project at a time; a second dispatch waits instead of racing. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->projectId))->releaseAfter(15)->expireAfter(7500)];
    }

    public function handle(PipelineOrchestrator $pipeline): void
    {
        if ($project = Project::find($this->projectId)) {
            $pipeline->advance($project);
        }
    }
}
