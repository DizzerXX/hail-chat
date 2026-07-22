<?php

namespace App\Jobs;

use App\Exceptions\LicenseApiException;
use App\Services\License\LicenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the local license pool in step with the platform: picks up licenses
 * added there and refreshes statuses/expiries. Licenses are stored locally, so
 * a failed sync never revokes access — it just leaves the pool as it was.
 */
class LicenseSyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(LicenseService $license): void
    {
        if (! $license->configured()) {
            return;
        }

        try {
            $license->sync();
        } catch (LicenseApiException $exception) {
            Log::warning('License sync failed; keeping the current pool.', [
                'error' => $exception->getMessage(),
                'unreachable' => $exception->unreachable,
            ]);
        }
    }
}
