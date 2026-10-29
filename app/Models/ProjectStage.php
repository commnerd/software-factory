<?php

namespace App\Models;

use App\Enums\SdlcStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectStage extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending', 'runs' => 0];

    protected function casts(): array
    {
        return [
            'stage' => SdlcStage::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function markRunning(): void
    {
        $this->update(['status' => 'running', 'started_at' => $this->started_at ?? now(), 'runs' => $this->runs + 1]);
    }

    public function markCompleted(?string $output = null): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'output' => $output ?? $this->output,
        ]);
    }
}
