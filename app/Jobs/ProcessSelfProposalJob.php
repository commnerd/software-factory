<?php

namespace App\Jobs;

use App\Models\SelfProposal;
use App\Services\SelfIteration\SelfIterationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSelfProposalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $proposalId) {}

    public function handle(SelfIterationService $self): void
    {
        if ($proposal = SelfProposal::find($this->proposalId)) {
            $self->process($proposal);
        }
    }
}
