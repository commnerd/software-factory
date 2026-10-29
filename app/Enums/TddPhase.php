<?php

namespace App\Enums;

enum TddPhase: string
{
    case Pending = 'pending';
    case Red = 'red';
    case Green = 'green';
    case Refactor = 'refactor';
    case Done = 'done';
    case Blocked = 'blocked';
}
