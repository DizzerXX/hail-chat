<?php

namespace App\Jobs;

use App\Exceptions\LicenseApiException;
use App\Services\License\LicenseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class LicenseHeartbeatJob implements ShouldQueue
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
            $license->heartbeat();
        } catch (LicenseApiException $exception) {
            // First-ever activation attempt failing (heartbeat() activates
            // when no state exists yet) — log, the grace/invalid logic in
            // isValid() decides access.
            Log::warning('License heartbeat failed.', ['error' => $exception->getMessage()]);
        }
    }
}
