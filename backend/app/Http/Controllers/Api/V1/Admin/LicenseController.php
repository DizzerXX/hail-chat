<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\LicenseApiException;
use App\Http\Controllers\Controller;
use App\Services\License\LicenseClient;
use App\Services\License\LicenseService;
use Illuminate\Http\JsonResponse;

class LicenseController extends Controller
{
    public function show(LicenseService $license, LicenseClient $client): JsonResponse
    {
        if (! $license->configured()) {
            return response()->json(['data' => ['configured' => false]]);
        }

        $state = $license->state();

        // Activation counts live on the platform listing; fetch tolerantly.
        $activations = $state?->current_activations;
        try {
            $licenses = $client->platformLicenses()['licenses'] ?? [];
            foreach ($licenses as $entry) {
                if (($entry['license_key'] ?? null) === config('license.key')) {
                    $activations = $entry['current_activations'] ?? $activations;
                }
            }
            $state?->update(['current_activations' => $activations]);
        } catch (LicenseApiException) {
            // Keep the cached figure.
        }

        return response()->json(['data' => [
            'configured' => true,
            'activated' => $state !== null,
            'valid' => $license->isValid(),
            'problem' => $license->problem(),
            'masked_key' => $license->maskedKey(),
            'status' => $state?->status,
            'type' => $state?->type,
            'expires_at' => $state?->expires_at,
            'days_remaining' => $state?->expires_at !== null
                ? max(0, (int) now()->diffInDays($state->expires_at, false))
                : null,
            'seats' => [
                'used' => $license->seatsUsed(),
                'max' => $license->maxUsers(),
            ],
            'activations' => [
                'used' => $activations,
                'max' => $state?->max_instances,
            ],
            'features' => $state?->features ?? [],
            'instance_id' => $state?->instance_id,
            'activated_at' => $state?->activated_at,
            'last_heartbeat_at' => $state?->last_heartbeat_at,
            'last_valid_at' => $state?->last_valid_at,
        ]]);
    }

    public function seats(LicenseService $license): JsonResponse
    {
        return response()->json(['data' => [
            'enforced' => $license->configured() && $license->maxUsers() !== null,
            'used' => $license->seatsUsed(),
            'max' => $license->maxUsers(),
        ]]);
    }

    public function revalidate(LicenseService $license): JsonResponse
    {
        abort_unless($license->configured(), 422, 'The license manager is not configured.');

        try {
            $license->activate();
        } catch (LicenseApiException $exception) {
            abort(502, $exception->getMessage());
        }

        return $this->show($license, app(LicenseClient::class));
    }

    public function deactivate(LicenseService $license): JsonResponse
    {
        abort_unless($license->configured(), 422, 'The license manager is not configured.');

        try {
            $license->deactivate();
        } catch (LicenseApiException $exception) {
            abort(502, $exception->getMessage());
        }

        return response()->json(['data' => ['deactivated' => true]]);
    }
}
