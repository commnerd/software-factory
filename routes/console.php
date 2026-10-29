<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Continuous improvement: kick off the next survey for projects that opted into an auto-cycle interval.
// (A human still chooses what gets built at the Backlog Review gate.)
Schedule::command('factory:cycle-due')->hourly()->withoutOverlapping();
