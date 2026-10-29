<?php

namespace App\Enums;

enum GateType: string
{
    case ArchitectureApproval = 'architecture_approval';
    case CostOverride = 'cost_override';
    case Deployment = 'deployment';
    case SelfModification = 'self_modification';
    case Intervention = 'intervention';

    public function label(): string
    {
        return match ($this) {
            self::ArchitectureApproval => 'Architecture approval',
            self::CostOverride => 'Cost override',
            self::Deployment => 'Deployment kick',
            self::SelfModification => 'Self-modification',
            self::Intervention => 'Needs intervention',
        };
    }
}
