<?php

use App\Jobs\LicenseSyncJob;
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
// Hourly licence sync: picks up licences added on the platform and refreshes
// statuses. Gated on the platform credentials being present.
if (config('license.url') !== '' && (string) config('license.platform_id') !== '') {
    Schedule::job(new LicenseSyncJob())->hourly();
}

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
