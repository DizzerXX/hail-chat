<?php

namespace App\Services\License;

use App\Models\Agent;
use App\Models\License;
use Illuminate\Support\Facades\DB;

/**
 * Licenses are a pool synced from the central platform, and one license
 * activates one agent. Users without an agent profile (admins, supervisors)
 * spend nothing.
 */
class LicenseService
{
    /** Enforcement only applies once the platform credentials are present. */
    public function configured(): bool
    {
        return config('license.url') !== ''
            && (string) config('license.platform_id') !== ''
            && (string) config('license.platform_access_token') !== '';
    }

    /**
     * Pull the pool from the platform, then hand free licenses to agents that
     * do not have one yet (oldest first) so enabling licensing never strands
     * agents that already exist.
     *
     * @return array{synced: int, added: int, assigned: int}
     */
    public function sync(): array
    {
        $client = app(LicenseClient::class);

        // sync-licenses activates them on the platform; platformLicenses then
        // supplies the detail the sync response omits.
        $client->syncLicenses();
        $records = $client->platformLicenses()['licenses'] ?? [];

        $added = 0;

        DB::transaction(function () use ($records, &$added) {
            foreach ($records as $record) {
                $id = $record['id'] ?? null;

                if ($id === null) {
                    continue;
                }

                $existing = License::where('platform_license_id', $id)->first();
                $added += $existing === null ? 1 : 0;

                License::updateOrCreate(
                    ['platform_license_id' => $id],
                    [
                        'license_key' => $record['license_key'] ?? '',
                        'status' => $record['status'] ?? null,
                        'type' => $record['type'] ?? null,
                        'customer_company' => $record['customer_company'] ?? null,
                        'issued_at' => $record['issued_at'] ?? null,
                        'expires_at' => $record['expires_at'] ?? null,
                        'activation_date' => $record['activation_date'] ?? null,
                        'max_users' => $record['max_users'] ?? null,
                        'max_instances' => $record['max_instances'] ?? null,
                        'features' => $record['features'] ?? [],
                        'metadata' => $record['metadata'] ?? null,
                        'last_synced_at' => now(),
                    ],
                );
            }
        });

        return [
            'synced' => count($records),
            'added' => $added,
            'assigned' => $this->assignPendingAgents(),
        ];
    }

    /**
     * Give a license to every active agent that lacks one, oldest agent first.
     * Stops when the pool runs dry — the shortfall shows on the admin screen.
     */
    public function assignPendingAgents(): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $pending = Agent::query()
            ->whereDoesntHave('license')
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();

        $assigned = 0;

        foreach ($pending as $agent) {
            if ($this->assign($agent) === null) {
                break;
            }

            $assigned++;
        }

        return $assigned;
    }

    /**
     * Claim a free license for an agent. Returns the license, the one they
     * already hold, or null when the pool is exhausted.
     */
    public function assign(Agent $agent): ?License
    {
        if (! $this->configured()) {
            return null;
        }

        $held = License::where('assigned_agent_id', $agent->id)->first();

        if ($held !== null) {
            return $held;
        }

        return DB::transaction(function () use ($agent) {
            $license = License::query()->usable()->unassigned()
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            $license?->forceFill([
                'assigned_agent_id' => $agent->id,
                'assigned_at' => now(),
            ])->save();

            return $license;
        });
    }

    /** Return a license to the pool. Deactivating an agent does NOT do this. */
    public function release(License $license): void
    {
        $license->forceFill(['assigned_agent_id' => null, 'assigned_at' => null])->save();
    }

    /** Usable licenses, whether or not they are spent. */
    public function capacity(): int
    {
        return License::query()->usable()->count();
    }

    public function seatsUsed(): int
    {
        return License::query()->whereNotNull('assigned_agent_id')->count();
    }

    public function freeSeats(): int
    {
        return max(0, $this->capacity() - $this->seatsUsed());
    }

    /** Whether another agent could be activated right now. */
    public function canActivateAgent(): bool
    {
        return ! $this->configured()
            || License::query()->usable()->unassigned()->exists();
    }

    /**
     * Unconfigured stays disabled-open. Otherwise we need at least one usable
     * license. Licenses live in our database, so an unreachable platform never
     * locks anyone out — statuses simply stop refreshing until the next sync.
     */
    public function isValid(): bool
    {
        return ! $this->configured() || $this->capacity() > 0;
    }

    /** Human-readable reason when isValid() is false; null when valid. */
    public function problem(): ?string
    {
        if ($this->isValid()) {
            return null;
        }

        return License::query()->exists()
            ? 'No license is active — every synced license is inactive or expired.'
            : 'No licenses have been synced yet. Run license:sync or use the admin license screen.';
    }

    public function hasFeature(string $feature): bool
    {
        if (! $this->configured()) {
            return true;
        }

        return License::query()->usable()->get()
            ->contains(fn (License $license) => in_array($feature, $license->features ?? [], true));
    }

    public function lastSyncedAt(): ?string
    {
        return License::query()->max('last_synced_at');
    }
}
