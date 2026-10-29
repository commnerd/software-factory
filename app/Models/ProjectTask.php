<?php

namespace App\Models;

use App\Enums\TddPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTask extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
        'tdd_phase' => 'pending',
        'type' => 'feature',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array',
            'files' => 'array',
            'tdd_phase' => TddPhase::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class);
    }

    public function setPhase(TddPhase $phase): void
    {
        $this->update(['tdd_phase' => $phase, 'status' => match ($phase) {
            TddPhase::Done => 'done',
            TddPhase::Blocked => 'blocked',
            TddPhase::Pending => 'pending',
            default => 'in_progress',
        }]);
    }
}
