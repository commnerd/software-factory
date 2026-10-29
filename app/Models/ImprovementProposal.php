<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImprovementProposal extends Model
{
    public const CATEGORIES = ['feature', 'enhancement', 'bugfix', 'tests', 'refactor', 'performance', 'security', 'docs', 'devex', 'dependency'];

    /** Still on the table for a backlog review. */
    public const OPEN = ['proposed', 'deferred'];

    protected $guarded = [];

    protected $attributes = ['status' => 'proposed', 'source' => 'survey', 'impact' => 3, 'effort' => 3, 'score' => 0];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'acceptance_criteria' => 'array',
            'files' => 'array',
            'score' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $p->score = self::score($p->impact, $p->effort);
        });
    }

    /** Value per unit of work: impact counts double, effort divides. 1.0 (trivial, low value) .. 10.0 (huge, trivial). */
    public static function score(int $impact, int $effort): float
    {
        $impact = max(1, min(5, $impact));
        $effort = max(1, min(5, $effort));

        return round(($impact * 2) / $effort, 2);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /** Characterization (tests that must pass against current code) vs. feature work that starts red. */
    public function taskType(): string
    {
        return $this->category === 'tests' ? 'characterization' : 'feature';
    }
}
