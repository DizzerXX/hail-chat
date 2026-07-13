<?php

namespace App\Console\Commands;

use App\Exceptions\LicenseApiException;
use App\Services\License\LicenseService;
use Illuminate\Console\Command;

class LicenseActivateCommand extends Command
{
    protected $signature = 'license:activate';

    protected $description = 'Activate this installation against the central License Manager';

    public function handle(LicenseService $license): int
    {
        if (! $license->configured()) {
            $this->error('LICENSE_MANAGER_URL and LICENSE_KEY must be set first.');

            return self::FAILURE;
        }

        try {
            $state = $license->activate();
        } catch (LicenseApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['instance_id', $state->instance_id],
            ['status', $state->status],
            ['type', $state->type ?? '—'],
            ['expires_at', $state->expires_at?->toDateString() ?? 'never'],
            ['max_users', $state->max_users ?? 'unlimited'],
            ['max_instances', $state->max_instances ?? 'unlimited'],
            ['features', implode(', ', $state->features ?? []) ?: '—'],
        ]);

        $this->info('License activated.');

        return self::SUCCESS;
    }
}
