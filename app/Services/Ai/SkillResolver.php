<?php

namespace App\Services\Ai;

use App\Enums\FactoryStep;
use App\Exceptions\FactoryException;
use App\Models\AiSkill;
use App\Models\AiSkillVersion;

/** Loads skills from the database at call time — edits take effect on the very next run. */
class SkillResolver
{
    public function forStep(FactoryStep $step): AiSkill
    {
        $skill = AiSkill::with('currentVersion')
            ->where('kind', 'agent')
            ->where('step', $step->value)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if (! $skill || ! $skill->currentVersion) {
            throw new FactoryException("No active AI skill is bound to step '{$step->value}'. Create one in Skills.");
        }

        return $skill;
    }

    public function version(AiSkill $skill, ?int $versionId = null): AiSkillVersion
    {
        if ($versionId) {
            return $skill->versions()->findOrFail($versionId);
        }

        return $skill->currentVersion ?? throw new FactoryException("Skill '{$skill->slug}' has no published version.");
    }
}
