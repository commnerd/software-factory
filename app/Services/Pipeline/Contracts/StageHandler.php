<?php

namespace App\Services\Pipeline\Contracts;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Services\Pipeline\StageResult;

interface StageHandler
{
    public function run(Project $project, ProjectStage $record): StageResult;
}
