<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModelRoute extends Model
{
    protected $guarded = [];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function modelConfiguration(): BelongsTo
    {
        return $this->belongsTo(ModelConfiguration::class);
    }

    public function fallback(): BelongsTo
    {
        return $this->belongsTo(ModelConfiguration::class, 'fallback_model_configuration_id');
    }
}
