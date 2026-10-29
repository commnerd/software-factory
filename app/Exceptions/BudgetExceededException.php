<?php

namespace App\Exceptions;

use App\Models\Project;

class BudgetExceededException extends FactoryException
{
    public function __construct(public readonly Project $project)
    {
        parent::__construct(sprintf(
            'Budget of $%s exceeded for "%s" (spent $%s).',
            number_format((float) $project->budget_limit_usd, 2),
            $project->name,
            number_format((float) $project->spent_usd, 2),
        ));
    }
}
