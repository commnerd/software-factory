<?php

namespace App\Console\Commands;

use App\Models\SelfProposal;
use App\Services\SelfIteration\SelfIterationService;
use Illuminate\Console\Command;

class ApplyStagedCommand extends Command
{
    protected $signature = 'factory:apply-staged {proposal : Self-proposal id} {--force : Skip the confirmation prompt}';

    protected $description = 'Apply a human-approved, staged self-modification patch to this checkout';

    public function handle(SelfIterationService $self): int
    {
        $proposal = SelfProposal::findOrFail($this->argument('proposal'));

        $this->line("Proposal #{$proposal->id}: {$proposal->title}");
        $this->line("Patch: {$proposal->staged_path}/changes.patch");

        if (! $this->option('force') && ! $this->confirm('Apply this patch to the working tree now?')) {
            return self::INVALID;
        }

        $self->applyStaged($proposal);
        $this->info('Applied. Review with `git diff`, then commit.');

        return self::SUCCESS;
    }
}
