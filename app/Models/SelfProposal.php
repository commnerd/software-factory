<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SelfProposal extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'proposed'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    public function gates(): HasMany
    {
        return $this->hasMany(ApprovalGate::class);
    }

    public function testSuites(): HasMany
    {
        return $this->hasMany(TestSuite::class);
    }
}
