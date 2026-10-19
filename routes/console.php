<?php

use Illuminate\Support\Facades\Schedule;

// Daily sync. Needs the system cron entry:
//   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
Schedule::command('sheets:sync --trigger=schedule')
    ->dailyAt(config('sheets.schedule_at'))
    ->withoutOverlapping()
    ->runInBackground();
