<?php

namespace App\Enums;

enum ModelTier: string
{
    case Cheap = 'cheap';
    case Standard = 'standard';
    case Frontier = 'frontier';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
