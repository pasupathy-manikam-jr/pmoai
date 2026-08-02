<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Poll live market quotes every 15 min during the wider trading window
// (covers Asian + US sessions). Needs the system cron entry:
//   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('pmoai:fetch-quotes')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
