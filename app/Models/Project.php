<?php

namespace App\Models;

use App\Enums\SdlcStage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'status' => 'draft',
        'current_stage' => 'assessment',
        'spent_usd' => 0,
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'assessment' => 'array',
            'existing_code' => 'boolean',
            'current_stage' => SdlcStage::class,
            'budget_limit_usd' => 'decimal:2',
            'spent_usd' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Project $p) {
            $p->slug ??= Str::slug($p->name).'-'.Str::lower(Str::random(5));
            $p->budget_limit_usd ??= config('factory.default_budget_usd');
        });

        static::created(function (Project $p) {
            foreach (SdlcStage::cases() as $stage) {
                $p->stages()->create(['stage' => $stage->value]);
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

    public function stage(SdlcStage $stage): ProjectStage
    {
        return $this->stages()->firstOrCreate(['stage' => $stage->value]);
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
}
