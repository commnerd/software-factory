<?php

namespace App\Exceptions;

use App\Models\ProjectTask;

/** Raised when a task cannot reach green/red within the configured attempts. */
class TddStuckException extends FactoryException
{
    public function __construct(public readonly ProjectTask $task, string $reason)
    {
        parent::__construct($reason);
    }
}
