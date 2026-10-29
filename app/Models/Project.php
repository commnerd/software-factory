<?php

namespace App\Models;

use App\Enums\Lifecycle;
use App\Enums\SdlcStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',          // draft|running|awaiting_approval|paused|failed|idle (released, waiting for the next cycle)
        'lifecycle' => 'build',
        'cycle' => 0,
        'current_stage' => 'assessment',
        'spent_usd' => 0,
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'assessment' => 'array',
            'existing_code' => 'boolean',
            'lifecycle' => Lifecycle::class,
            'current_stage' => SdlcStage::class,
            'budget_limit_usd' => 'decimal:2',
            'spent_usd' => 'decimal:4',
            'cycle_started_at' => 'datetime',
            'last_cycle_completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $p) {
            $p->slug ??= Str::slug($p->name).'-'.Str::lower(Str::random(5));
            $p->budget_limit_usd ??= config('factory.default_budget_usd');
        });

        static::created(function (Project $p) {
            foreach (SdlcStage::sequence(Lifecycle::Build) as $stage) {
                $p->stages()->create(['stage' => $stage->value, 'cycle' => 0]);
            }
        });
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class);
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('position');
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(ImprovementProposal::class);
    }

    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function gates(): HasMany
    {
        return $this->hasMany(ApprovalGate::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(ProjectArtifact::class);
    }

    public function modelRoutes(): HasMany
    {
        return $this->hasMany(ModelRoute::class);
    }

    /** The stage record for the current cycle (created on demand). */
    public function stage(SdlcStage $stage, ?int $cycle = null): ProjectStage
    {
        return $this->stages()->firstOrCreate(['stage' => $stage->value, 'cycle' => $cycle ?? $this->cycle]);
    }

    /**
     * The latest non-empty output of a stage across cycles. Requirements/architecture are produced once
     * (cycle 0, or recovered during assessment) but every later cycle needs them as context.
     */
    public function knowledge(SdlcStage $stage): string
    {
        return (string) $this->stages()->where('stage', $stage->value)->whereNotNull('output')->where('output', '!=', '')
            ->orderByDesc('cycle')->value('output');
    }

    /** Tasks belonging to the cycle that is currently being worked. */
    public function cycleTasks(): HasMany
    {
        return $this->tasks()->where('cycle', $this->cycle);
    }

    public function budgetExceeded(): bool
    {
        return $this->budget_limit_usd !== null
            && (float) $this->spent_usd >= (float) $this->budget_limit_usd;
    }

    /** Language profile from config with per-project overrides applied. */
    public function profile(): array
    {
        $base = config("factory.languages.{$this->language}")
            ?? config('factory.languages.node');

        $overrides = array_filter($this->config ?? [], fn ($v) => $v !== null && $v !== '');

        return array_merge($base, $overrides);
    }

    /** @return list<SdlcStage> */
    public function sequence(): array
    {
        return SdlcStage::sequence($this->lifecycle);
    }
}
