<?php

namespace App\Enums;

enum SdlcStage: string
{
    // shared
    case Assessment = 'assessment';
    case TaskBreakdown = 'task_breakdown';
    case TddImplementation = 'tdd_implementation';
    case Qa = 'qa';
    case DeploymentPrep = 'deployment_prep';

    // build lifecycle only
    case Requirements = 'requirements';
    case Architecture = 'architecture';

    // improve lifecycle only
    case Survey = 'survey';
    case Backlog = 'backlog';
    case Retrospective = 'retrospective';

    /** @return list<self> ordered stages of a lifecycle */
    public static function sequence(Lifecycle $lifecycle): array
    {
        return match ($lifecycle) {
            Lifecycle::Build => [
                self::Assessment, self::Requirements, self::Architecture, self::TaskBreakdown,
                self::TddImplementation, self::Qa, self::DeploymentPrep,
            ],
            Lifecycle::Improve => [
                self::Survey, self::Backlog, self::TaskBreakdown,
                self::TddImplementation, self::Qa, self::DeploymentPrep, self::Retrospective,
            ],
        };
    }

    public static function first(Lifecycle $lifecycle): self
    {
        return self::sequence($lifecycle)[0];
    }

    public function next(Lifecycle $lifecycle): ?self
    {
        $seq = self::sequence($lifecycle);
        $i = array_search($this, $seq, true);

        return $i === false ? null : ($seq[$i + 1] ?? null);
    }

    public function label(?Lifecycle $lifecycle = null): string
    {
        $improve = $lifecycle === Lifecycle::Improve;

        return match ($this) {
            self::Assessment => 'Import & Assessment',
            self::Requirements => 'Requirement Analysis',
            self::Architecture => 'Architecture Design',
            self::TaskBreakdown => $improve ? 'Cycle Planning' : 'Task Breakdown',
            self::TddImplementation => 'TDD Implementation',
            self::Qa => 'QA / Testing',
            self::DeploymentPrep => $improve ? 'Release Prep' : 'Deployment Prep',
            self::Survey => 'Survey & Propose',
            self::Backlog => 'Backlog Review',
            self::Retrospective => 'Retrospective',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(Lifecycle $lifecycle): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label($lifecycle)], self::sequence($lifecycle));
    }
}
