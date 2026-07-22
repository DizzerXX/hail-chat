<?php

namespace App\Console\Commands;

use App\Exceptions\LicenseApiException;
use App\Models\License;
use App\Services\License\LicenseService;
use Illuminate\Console\Command;

class LicenseSyncCommand extends Command
{
    protected $signature = 'license:sync';

    protected $description = 'Sync licenses from the central platform and assign them to agents';

    public function handle(LicenseService $license): int
    {
        if (! $license->configured()) {
            $this->error('LICENSE_MANAGER_URL, LICENSE_PLATFORM_ID and LICENSE_PLATFORM_ACCESS_TOKEN must be set first.');

            return self::FAILURE;
        }

        try {
            $result = $license->sync();
        } catch (LicenseApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Synced %d license(s) — %d new, %d newly assigned to agents.',
            $result['synced'],
            $result['added'],
            $result['assigned'],
        ));

        $this->table(
            ['License', 'Status', 'Expires', 'Agent'],
            License::with('agent')->orderBy('id')->get()->map(fn (License $l) => [
                $l->maskedKey(),
                $l->status ?? '—',
                $l->expires_at?->toDateString() ?? 'never',
                $l->agent?->displayName() ?? '(free)',
            ]),
        );

        $this->line(sprintf(
            '%d of %d usable license(s) assigned.',
            $license->seatsUsed(),
            $license->capacity(),
        ));

        return self::SUCCESS;
    }
}
