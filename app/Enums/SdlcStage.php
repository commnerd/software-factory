<?php

namespace App\Enums;

enum SdlcStage: string
{
    case Requirements = 'requirements';
    case Architecture = 'architecture';
    case TaskBreakdown = 'task_breakdown';
    case TddImplementation = 'tdd_implementation';
    case Qa = 'qa';
    case DeploymentPrep = 'deployment_prep';

    public function label(): string
    {
        return match ($this) {
            self::Requirements => 'Requirement Analysis',
            self::Architecture => 'Architecture Design',
            self::TaskBreakdown => 'Task Breakdown',
            self::TddImplementation => 'TDD Implementation',
            self::Qa => 'QA / Testing',
            self::DeploymentPrep => 'Deployment Prep',
        };
    }

    public function next(): ?self
    {
        $cases = self::cases();
        $i = array_search($this, $cases, true);

        return $cases[$i + 1] ?? null;
    }

    public static function first(): self
    {
        return self::Requirements;
    }
}
