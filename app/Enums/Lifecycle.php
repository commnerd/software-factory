<?php

namespace App\Enums;

/**
 * Build   — a new project, taken from nothing to a first release.
 * Improve — everything after: survey → choose → plan → TDD → QA → release → retrospective, in numbered cycles.
 */
enum Lifecycle: string
{
    case Build = 'build';
    case Improve = 'improve';

    public function label(): string
    {
        return match ($this) {
            self::Build => 'New build',
            self::Improve => 'Continuous improvement',
        };
    }
}
