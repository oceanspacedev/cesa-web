<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

set_time_limit(0);
ini_set('max_execution_time', '0');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('rekrutmen:process-scheduled-notifications')
    ->everyMinute()
    ->withoutOverlapping();
