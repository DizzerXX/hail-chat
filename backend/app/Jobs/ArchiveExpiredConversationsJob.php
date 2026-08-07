<?php

namespace App\Jobs;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\SystemSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Data-retention sweep: when a closed/abandoned conversation is older than the
 * configured window it is *archived* — soft-deleted into the recycle bin
 * (deleted_by null = system) rather than removed outright. It then sits there
 * for recycle_bin_days, still restorable, before PurgeRecycleBinJob deletes it
 * for good. Opt-in; a hard floor guards against wiping recent data.
 */
class ArchiveExpiredConversationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        // Long-lived worker: re-read so admin edits to the policy take effect.
        SystemSettings::forgetCurrent();
        $settings = SystemSettings::current();

        if (! $settings->retention_enabled) {
            return;
        }

        $days = max((int) config('system.retention_min_days', 7), (int) $settings->retention_days);
        $cutoff = now()->subDays($days);

        $archived = 0;

        // Already-archived rows are excluded by the soft-delete scope, so this
        // never re-archives. A plain ->delete() soft-deletes (deleted_by stays
        // null, marking it as a system/retention archive).
        Conversation::query()
            ->whereIn('status', [ConversationStatus::Closed, ConversationStatus::Abandoned])
            ->where('closed_at', '<', $cutoff)
            ->select('id')
            ->chunkById(200, function ($conversations) use (&$archived) {
                $archived += Conversation::whereIn('id', $conversations->pluck('id'))->delete();
            });

        $settings->forceFill(['last_purge_at' => now(), 'last_purge_count' => $archived])->save();
        SystemSettings::forgetCurrent();

        if ($archived > 0) {
            Log::info('Retention archived conversations to the recycle bin.', ['count' => $archived, 'older_than_days' => $days]);
        }
    }
}
