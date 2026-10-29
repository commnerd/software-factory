<?php

namespace App\Models;

use App\Enums\ModelTier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelConfiguration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'tier' => ModelTier::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'input_cost_per_mtok' => 'float',
            'output_cost_per_mtok' => 'float',
        ];
    }

    public function routes(): HasMany
    {
        return $this->hasMany(ModelRoute::class);
    }

    public function cost(int $inputTokens, int $outputTokens): float
    {
        return ($inputTokens * $this->input_cost_per_mtok + $outputTokens * $this->output_cost_per_mtok) / 1_000_000;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }
}
