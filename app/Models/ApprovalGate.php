<?php

namespace App\Models;

use App\Enums\GateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalGate extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'type' => GateType::class,
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function selfProposal(): BelongsTo
    {
        return $this->belongsTo(SelfProposal::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
