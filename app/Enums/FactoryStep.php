<?php

namespace App\Enums;

/**
 * A "step" is the unit that models and skills are bound to. A stage may use
 * several steps (TDD implementation = red + green + refactor).
 */
enum FactoryStep: string
{
    case ProjectAssessment = 'project_assessment';
    case FeatureSurvey = 'feature_survey';
    case CycleRetrospective = 'cycle_retrospective';
    case RequirementsAnalysis = 'requirements_analysis';
    case ArchitectureDesign = 'architecture_design';
    case TaskBreakdown = 'task_breakdown';
    case TddRed = 'tdd_red';
    case TddGreen = 'tdd_green';
    case TddRefactor = 'tdd_refactor';
    case QaReview = 'qa_review';
    case CiWorkflow = 'ci_workflow';
    case DeploymentPrep = 'deployment_prep';
    case SelfAnalysis = 'self_analysis';
    case SelfImplementation = 'self_implementation';

    public function label(): string
    {
        return match ($this) {
            self::ProjectAssessment => 'Existing-code assessment',
            self::FeatureSurvey => 'Survey: propose improvements',
            self::CycleRetrospective => 'Cycle retrospective',
            self::RequirementsAnalysis => 'Requirements analysis',
            self::ArchitectureDesign => 'Architecture design',
            self::TaskBreakdown => 'Task breakdown',
            self::TddRed => 'TDD · Red (write failing tests)',
            self::TddGreen => 'TDD · Green (implement)',
            self::TddRefactor => 'TDD · Refactor',
            self::QaReview => 'QA review',
            self::CiWorkflow => 'CI/CD workflow generation',
            self::DeploymentPrep => 'Deployment prep',
            self::SelfAnalysis => 'Self · analyse codebase',
            self::SelfImplementation => 'Self · implement proposal',
        };
    }

    public function stage(): ?SdlcStage
    {
        return match ($this) {
            self::ProjectAssessment => SdlcStage::Assessment,
            self::FeatureSurvey => SdlcStage::Survey,
            self::CycleRetrospective => SdlcStage::Retrospective,
            self::RequirementsAnalysis => SdlcStage::Requirements,
            self::ArchitectureDesign => SdlcStage::Architecture,
            self::TaskBreakdown => SdlcStage::TaskBreakdown,
            self::TddRed, self::TddGreen, self::TddRefactor => SdlcStage::TddImplementation,
            self::QaReview => SdlcStage::Qa,
            self::CiWorkflow, self::DeploymentPrep => SdlcStage::DeploymentPrep,
            self::SelfAnalysis, self::SelfImplementation => null,
        };
    }

    /** Cost-effective models for parsing/scaffolding, frontier models for reasoning and code. */
    public function defaultTier(): ModelTier
    {
        return match ($this) {
            self::RequirementsAnalysis, self::TaskBreakdown, self::CiWorkflow, self::DeploymentPrep, self::CycleRetrospective => ModelTier::Cheap,
            self::TddRefactor, self::QaReview, self::ProjectAssessment => ModelTier::Standard,
            self::FeatureSurvey, self::ArchitectureDesign, self::TddRed, self::TddGreen, self::SelfAnalysis, self::SelfImplementation => ModelTier::Frontier,
        };
    }

    /** @return array<int, array{value: string, label: string, stage: ?string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'stage' => $s->stage()?->value,
        ], self::cases());
    }
}
