<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiSkillVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['variables' => 'array'];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(AiSkill::class, 'ai_skill_id');
    }
}
