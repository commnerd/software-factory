<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class AiSkill extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AiSkillVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AiSkillVersion::class, 'current_version_id');
    }

    /**
     * Publish a new immutable version and make it current.
     *
     * @param  array{system_prompt?: ?string, user_template: string, variables?: ?array, output_format?: string, change_note?: ?string, created_by?: ?string}  $data
     */
    public function publish(array $data): AiSkillVersion
    {
        return DB::transaction(function () use ($data) {
            $next = (int) $this->versions()->max('version') + 1;

            $version = $this->versions()->create([
                'version' => $next,
                'system_prompt' => $data['system_prompt'] ?? null,
                'user_template' => $data['user_template'],
                'variables' => $data['variables'] ?? null,
                'output_format' => $data['output_format'] ?? 'text',
                'change_note' => $data['change_note'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $this->update(['current_version_id' => $version->id]);

            return $version;
        });
    }

    /** Roll forward/back by pointing "current" at an existing version. */
    public function activate(AiSkillVersion $version): void
    {
        abort_unless($version->ai_skill_id === $this->id, 404);
        $this->update(['current_version_id' => $version->id]);
    }
}
