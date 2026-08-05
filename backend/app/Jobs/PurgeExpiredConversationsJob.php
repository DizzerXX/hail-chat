<?php

namespace App\Jobs;

use App\Enums\ConversationStatus;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\SystemSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Data-retention sweep: permanently deletes conversations (and their messages,
 * attachments, events) that closed longer ago than the configured window. Only
 * closed/abandoned conversations are eligible — open chats are never touched —
 * and a hard floor (system.retention_min_days) guards against wiping recent data.
 *
 * This is irreversible by design (hard delete). Runs daily; opt-in.
 */
class PurgeExpiredConversationsJob implements ShouldQueue
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

        $purged = 0;

        Conversation::query()
            ->whereIn('status', [ConversationStatus::Closed, ConversationStatus::Abandoned])
            ->where('closed_at', '<', $cutoff)
            ->select('id')
            ->chunkById(200, function ($conversations) use (&$purged) {
                $ids = $conversations->pluck('id');

                // Remove the stored files first — the DB cascade drops the rows
                // but not the blobs on disk.
                Attachment::query()
                    ->whereHas('message', fn ($query) => $query->whereIn('conversation_id', $ids))
                    ->get(['disk', 'path'])
                    ->each(fn (Attachment $attachment) => Storage::disk($attachment->disk)->delete($attachment->path));

                $purged += Conversation::whereIn('id', $ids)->delete();
            });

        $settings->forceFill(['last_purge_at' => now(), 'last_purge_count' => $purged])->save();
        SystemSettings::forgetCurrent();

        if ($purged > 0) {
            Log::info('Retention sweep purged conversations.', ['count' => $purged, 'older_than_days' => $days]);
        }
    }
}
