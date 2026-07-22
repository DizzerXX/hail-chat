<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\LicenseApiException;
use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\License\LicenseService;
use Illuminate\Http\JsonResponse;

class LicenseController extends Controller
{
    public function show(LicenseService $license): JsonResponse
    {
        if (! $license->configured()) {
            return response()->json(['data' => ['configured' => false]]);
        }

        return response()->json(['data' => [
            'configured' => true,
            'valid' => $license->isValid(),
            'problem' => $license->problem(),
            'platform_slug' => config('license.platform_slug'),
            'last_synced_at' => $license->lastSyncedAt(),
            'seats' => [
                'used' => $license->seatsUsed(),
                'max' => $license->capacity(),
                'free' => $license->freeSeats(),
            ],
            'licenses' => License::with('agent')->orderBy('platform_license_id')->get()
                ->map(fn (License $entry) => [
                    'id' => $entry->id,
                    'masked_key' => $entry->maskedKey(),
                    'status' => $entry->status,
                    'type' => $entry->type,
                    'usable' => $entry->isUsable(),
                    'expires_at' => $entry->expires_at,
                    'days_remaining' => $entry->expires_at !== null
                        ? max(0, (int) now()->diffInDays($entry->expires_at, false))
                        : null,
                    'features' => $entry->features ?? [],
                    'assigned_agent' => $entry->agent === null ? null : [
                        'id' => $entry->agent->id,
                        'display_name' => $entry->agent->displayName(),
                    ],
                    'assigned_at' => $entry->assigned_at,
                ]),
        ]]);
    }

    /** Powers the seat banner on the Users screen. */
    public function seats(LicenseService $license): JsonResponse
    {
        return response()->json(['data' => [
            'enforced' => $license->configured(),
            'used' => $license->seatsUsed(),
            'max' => $license->configured() ? $license->capacity() : null,
        ]]);
    }

    public function sync(LicenseService $license): JsonResponse
    {
        abort_unless($license->configured(), 422, 'The license platform is not configured.');

        try {
            $license->sync();
        } catch (LicenseApiException $exception) {
            abort(502, $exception->getMessage());
        }

        return $this->show($license);
    }

    /** Hands a license back to the pool so another agent can take it. */
    public function release(LicenseService $license, License $licenseRecord): JsonResponse
    {
        $license->release($licenseRecord);

        return $this->show($license);
    }
}
