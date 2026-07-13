<?php

namespace App\Services\License;

use App\Exceptions\LicenseApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Wraps the central License Manager's four endpoints. Every call is a JSON
 * POST answering {success, message, data} (HTTP 400 on failure). Non-2xx or
 * success=false surface as LicenseApiException; connection failures as a
 * LicenseApiException with unreachable=true so callers can apply the grace
 * window. The legacy /api/v1/validate/email endpoint is intentionally not
 * wrapped — it no longer exists.
 */
class LicenseClient
{
    /**
     * Activates (or re-validates) this installation's instance slot.
     */
    public function validateLicense(array $payload): array
    {
        return $this->post('/api/v1/validate/license', $payload);
    }

    /**
     * Heartbeat — returns valid, status, expires_at, days_until_expiration.
     */
    public function check(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/v1/validate/check', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Frees this installation's activation slot.
     */
    public function deactivate(string $licenseKey, string $instanceId): array
    {
        return $this->post('/api/v1/validate/deactivate', [
            'license_key' => $licenseKey,
            'instance_id' => $instanceId,
        ]);
    }

    /**
     * Lists all licenses for our platform. Authenticated by platform_id +
     * access_token in the BODY (no header).
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
