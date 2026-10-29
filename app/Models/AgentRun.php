<?php

namespace App\Models;

use App\Support\JsonExtractor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'running'];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'cost_usd' => 'decimal:6',
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

    public function skill(): BelongsTo
    {
        return $this->belongsTo(AiSkill::class, 'ai_skill_id');
    }

    public function skillVersion(): BelongsTo
    {
        return $this->belongsTo(AiSkillVersion::class, 'ai_skill_version_id');
    }

    public function modelConfiguration(): BelongsTo
    {
        return $this->belongsTo(ModelConfiguration::class);
    }

    /** Decode the response as JSON (tolerates code fences / surrounding prose). */
    public function json(): ?array
    {
        return JsonExtractor::extract((string) $this->response);
    }
}
