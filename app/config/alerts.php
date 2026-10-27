<?php

/*
|--------------------------------------------------------------------------
| Operational alert scheduling (process.md items 5 & 6)
|--------------------------------------------------------------------------
|
| Alerts run on the IT team's working clock, independently of the app's UTC
| storage timezone, so "5 PM" means 5 PM where the team actually works.
|
*/

return [
    'timezone' => env('ALERT_TIMEZONE', 'Asia/Manila'),

    // Daily reminder for ticket requests still awaiting IT action.
    'pending' => [
        'enabled' => env('ALERT_PENDING_ENABLED', true),
        'at' => env('ALERT_PENDING_AT', '17:00'),

        // Statuses counted as "pending": anything not yet resolved or closed.
        'statuses' => ['new', 'assigned', 'in_progress', 'pending'],
    ],

    // Weekly monitoring summary, produced for review on Friday.
    'weekly' => [
        'enabled' => env('ALERT_WEEKLY_ENABLED', true),
        'day' => (int) env('ALERT_WEEKLY_DAY', 5), // 0 = Sunday … 5 = Friday
        'at' => env('ALERT_WEEKLY_AT', '17:00'),
    ],
];
