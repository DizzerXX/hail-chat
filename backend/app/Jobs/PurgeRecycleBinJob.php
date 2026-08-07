<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Empties the recycle bin: permanently deletes conversations (rows + attachment
 * files, cascading messages/events) that have sat soft-deleted longer than
 * recycle_bin_days. Covers both manual admin deletes and retention archiving.
 * Irreversible — runs daily.
 */
class PurgeRecycleBinJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(ConversationService $conversations): void
    {
        $cutoff = now()->subDays((int) config('system.recycle_bin_days', 30));
        $purged = 0;

        Conversation::onlyTrashed()
            ->where('deleted_at', '<', $cutoff)
            ->select('id')
            ->chunkById(200, function ($chunk) use (&$purged, $conversations) {
                $ids = $chunk->pluck('id');
                $conversations->deleteAttachmentFiles($ids);
                $purged += Conversation::withTrashed()->whereIn('id', $ids)->forceDelete();
            });

        if ($purged > 0) {
            Log::info('Recycle bin purged conversations permanently.', ['count' => $purged]);
        }
    }
}
