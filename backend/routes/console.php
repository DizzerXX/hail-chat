<?php

use App\Jobs\IdleSweepJob;
use App\Jobs\LicenseSyncJob;
use App\Jobs\PollOpenMediaWorkItems;
use App\Jobs\ArchiveExpiredConversationsJob;
use App\Jobs\PurgeRecycleBinJob;
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

// Idle handling for standalone conversations: warn/close silent customers and
// nudge idle agents. Runs every minute; the job itself honours idle_enabled.
Schedule::job(new IdleSweepJob())->everyMinute()->withoutOverlapping();

// Retention archives expired conversations into the recycle bin; the bin purge
// permanently deletes anything that has sat there past recycle_bin_days.
// Both honour their settings/floors and are safe to run daily.
Schedule::job(new ArchiveExpiredConversationsJob())->dailyAt('03:30')->withoutOverlapping();
Schedule::job(new PurgeRecycleBinJob())->dailyAt('03:45')->withoutOverlapping();

// Drop expired API tokens (past the absolute cap) so personal_access_tokens
// doesn't accumulate dead rows.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

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
