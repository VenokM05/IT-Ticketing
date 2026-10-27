<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled operational alerts (process.md items 5 & 6)
|--------------------------------------------------------------------------
|
| Times are interpreted in config('alerts.timezone') — the IT team's working
| clock — rather than the app's UTC storage timezone. Requires the Laravel
| scheduler to be running: `php artisan schedule:work` in development, or a
| task-scheduler entry calling `php artisan schedule:run` every minute.
|
*/

if (config('alerts.pending.enabled')) {
    Schedule::command('tickets:pending-alert')
        ->dailyAt(config('alerts.pending.at'))
        ->timezone(config('alerts.timezone'))
        ->name('daily-pending-ticket-alert')
        ->withoutOverlapping()
        ->onOneServer();
}

if (config('alerts.weekly.enabled')) {
    Schedule::command('tickets:weekly-summary')
        ->weeklyOn((int) config('alerts.weekly.day'), config('alerts.weekly.at'))
        ->timezone(config('alerts.timezone'))
        ->name('weekly-ticket-summary')
        ->withoutOverlapping()
        ->onOneServer();
}
