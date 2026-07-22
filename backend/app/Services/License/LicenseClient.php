<?php

namespace App\Services\License;

use App\Exceptions\LicenseApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wraps the platform endpoints TDEChat uses. Every call is a JSON POST
 * answering {success, message, data}; post() returns the unwrapped data.
 * Non-2xx or success=false surface as LicenseApiException, connection failures
 * with unreachable=true so callers can distinguish "rejected" from "offline".
 *
 * Both endpoints authenticate with platform_id + access_token in the BODY (no
 * header). The /api/v1/validate/* endpoints are deliberately absent: TDEChat
 * spends licenses per agent from a synced pool rather than activating a single
 * per-installation key.
 */
class LicenseClient
{
    /**
     * Syncs our platform's licenses. Side effect on the platform: synced
     * licenses flip from inactive to active. Idempotent — re-running returns
     * the same set with synced_count 0.
     *
     * @return array{platform: array, sync_time: string, synced_count: int, synced_licenses: list<array>}
     */
    public function syncLicenses(): array
    {
        return $this->post('/api/v1/admin/platforms/sync-licenses', [
            'platform_id' => config('license.platform_id'),
            'access_token' => config('license.platform_access_token'),
        ]);
    }

    /**
     * Lists our platform's licenses with their full detail — type, max_users,
     * features, metadata — which the sync response does not carry.
     *
     * @return array{platform: array, licenses: list<array>, total_licenses: int}
     */
    public function platformLicenses(): array
    {
        return $this->post('/api/v1/admin/platforms/licenses', [
            'platform_id' => config('license.platform_id'),
            'access_token' => config('license.platform_access_token'),
        ]);
    }

    private function post(string $path, array $payload): array
    {
        try {
            $response = $this->request()->post(config('license.url').$path, $payload);
        } catch (Throwable $exception) {
            throw new LicenseApiException(
                'License manager unreachable: '.$exception->getMessage(),
                unreachable: true,
                previous: $exception,
            );
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            throw new LicenseApiException(
                (string) ($body['message'] ?? 'License manager rejected the request (HTTP '.$response->status().').'),
            );
        }

        return $body['data'] ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->timeout(config('license.timeout', 30));
    }
}
