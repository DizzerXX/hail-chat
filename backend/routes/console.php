<?php

use App\Jobs\PollOpenMediaWorkItems;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * MiCC Open Media state polling — only when the integration is enabled.
 * Requires `php artisan schedule:work` (sub-minute cadences need it even
 * on servers with a classic cron entry).
 */
if (config('mitel.enabled')) {
    $poll = Schedule::job(new PollOpenMediaWorkItems())->withoutOverlapping();

    match ((int) config('mitel.polling_interval', 15)) {
        1 => $poll->everySecond(),
        2 => $poll->everyTwoSeconds(),
        5 => $poll->everyFiveSeconds(),
        10 => $poll->everyTenSeconds(),
        15 => $poll->everyFifteenSeconds(),
        20 => $poll->everyTwentySeconds(),
        30 => $poll->everyThirtySeconds(),
        default => $poll->everyMinute(),
    };
}
