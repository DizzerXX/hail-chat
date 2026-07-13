<?php

namespace App\Services\License;

use App\Exceptions\LicenseApiException;
use App\Models\LicenseState;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class LicenseService
{
    private ?LicenseState $cached = null;

    private bool $loaded = false;

    /** License enforcement only applies when the manager is configured. */
    public function configured(): bool
    {
        return config('license.url') !== '' && config('license.key') !== '';
    }

    public function state(): ?LicenseState
    {
        if (! $this->loaded) {
            $this->cached = LicenseState::first();
            $this->loaded = true;
        }

        return $this->cached;
    }

    /**
     * Stable installation id — deterministic (app key + hostname) and
     * persisted with the state so an activation slot is never re-consumed.
     */
    public function instanceId(): string
    {
        return $this->state()->instance_id
            ?? hash('sha256', config('app.key').'|'.gethostname());
    }

    /**
     * Full validate/license call — activates the instance slot and persists
     * the returned license payload.
     */
    public function activate(): LicenseState
    {
        $client = app(LicenseClient::class);

        $data = $client->validateLicense([
            'license_key' => config('license.key'),
            'platform_slug' => config('license.platform_slug'),
            'instance_id' => $this->instanceId(),
            'domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            'ip_address' => gethostbyname((string) gethostname()),
            'server_info' => PHP_OS_FAMILY.' / PHP '.PHP_VERSION,
            'application_version' => '1.0.0',
        ]);

        $license = $data['license'] ?? [];

        $state = LicenseState::updateOrCreate(
            ['instance_id' => $this->instanceId()],
            [
                'status' => $license['status'] ?? 'unknown',
                'type' => $license['type'] ?? null,
                'expires_at' => $license['expires_at'] ?? null,
                'max_users' => $license['max_users'] ?? null,
                'max_instances' => $license['max_instances'] ?? null,
                'features' => $license['features'] ?? [],
                'metadata' => $license['metadata'] ?? null,
                'activated_at' => now(),
                'last_heartbeat_at' => now(),
                'last_valid_at' => now(),
            ],
        );

        $this->cached = $state;
        $this->loaded = true;

        return $state;
    }

    /**
     * validate/check heartbeat. Unreachable managers leave the last known
     * good state intact — isValid() applies the grace window.
     */
    public function heartbeat(): void
    {
        $state = $this->state();

        if ($state === null) {
            $this->activate();

            return;
        }

        try {
            $data = app(LicenseClient::class)->check(config('license.key'), $this->instanceId());
        } catch (LicenseApiException $exception) {
            if ($exception->unreachable) {
                Log::warning('License heartbeat: manager unreachable; keeping last known state.', [
                    'error' => $exception->getMessage(),
                ]);

                return;
            }

            // The manager answered and rejected us — the license is invalid.
            $state->update(['status' => 'invalid', 'last_heartbeat_at' => now()]);
            $this->cached = $state->fresh();

            return;
        }

        $valid = ($data['valid'] ?? false) === true;

        $state->update([
            'status' => $data['status'] ?? ($valid ? 'active' : 'invalid'),
            'expires_at' => $data['expires_at'] ?? $state->expires_at,
            'last_heartbeat_at' => now(),
            ...($valid ? ['last_valid_at' => now()] : []),
        ]);

        $this->cached = $state->fresh();
    }

    public function deactivate(): void
    {
        app(LicenseClient::class)->deactivate(config('license.key'), $this->instanceId());

        LicenseState::query()->delete();
        $this->cached = null;
        $this->loaded = true;
    }

    public function isValid(): bool
    {
        if (! $this->configured()) {
            return true;
        }

        $state = $this->state();

        if ($state === null || $state->status !== 'active') {
            return false;
        }

        if ($state->expires_at !== null && $state->expires_at->isPast()) {
            return false;
        }

        $graceDeadline = ($state->last_valid_at ?? $state->activated_at)
            ?->addHours(config('license.grace_hours', 72));

        return $graceDeadline === null || $graceDeadline->isFuture();
    }

    /** Human-readable reason when isValid() is false; null when valid. */
    public function problem(): ?string
    {
        if (! $this->configured() || $this->isValid()) {
            return null;
        }

        $state = $this->state();

        if ($state === null) {
            return 'This installation has not been activated. Run license:activate or use the admin license screen.';
        }

        if ($state->expires_at !== null && $state->expires_at->isPast()) {
            return 'The license expired on '.$state->expires_at->toDateString().'.';
        }

        if ($state->status !== 'active') {
            return 'The license is '.($state->status ?? 'invalid').'.';
        }

        return 'The license could not be re-validated within the grace period.';
    }

    public function hasFeature(string $feature): bool
    {
        if (! $this->configured()) {
            return true;
        }

        return in_array($feature, $this->state()?->features ?? [], true);
    }

    public function seatsUsed(): int
    {
        return User::where('is_active', true)->count();
    }

    public function maxUsers(): ?int
    {
        return $this->configured() ? $this->state()?->max_users : null;
    }

    public function canAddSeat(): bool
    {
        $max = $this->maxUsers();

        return $max === null || $this->seatsUsed() < $max;
    }

    public function maskedKey(): ?string
    {
        $key = (string) config('license.key');

        if ($key === '') {
            return null;
        }

        return strlen($key) <= 8
            ? str_repeat('•', strlen($key))
            : substr($key, 0, 4).str_repeat('•', max(4, strlen($key) - 8)).substr($key, -4);
    }
}
