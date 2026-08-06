<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\License\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Read-only health snapshot for the System Settings screen — turns the manual
 * CLI checks (redis, db, reverb, queue, mitel, license) into one panel.
 */
class SystemStatusController extends Controller
{
    public function show(LicenseService $license): JsonResponse
    {
        // Only treat Redis as a health signal when the app actually uses it —
        // otherwise a box on file/database drivers would show a permanent (and
        // meaningless) red.
        $usesRedis = in_array('redis', [
            config('cache.default'),
            config('queue.default'),
            config('session.driver'),
        ], true);

        return response()->json(['data' => [
            'version' => config('app.version'),
            'database' => $this->ok(fn () => DB::connection()->getPdo() !== null),
            'redis' => [
                'used' => $usesRedis,
                'up' => $usesRedis ? $this->ok(fn () => (string) Redis::connection()->ping() !== '') : true,
            ],
            'reverb' => $this->ok(fn () => $this->tcpReachable(
                (string) config('broadcasting.connections.reverb.options.host'),
                (int) config('broadcasting.connections.reverb.options.port'),
            )),
            'queue' => [
                'up' => $this->ok(fn () => Queue::size() >= 0),
                'pending' => $this->safe(fn () => Queue::size(), 0),
            ],
            'mitel' => [
                'enabled' => (bool) config('mitel.enabled'),
                'configured' => config('mitel.base_url') !== '',
            ],
            'license' => [
                'enforced' => $license->configured(),
                'used' => $this->safe(fn () => $license->seatsUsed(), 0),
                'max' => $license->configured() ? $this->safe(fn () => $license->capacity(), 0) : null,
            ],
        ]]);
    }

    /** @param callable():bool $check */
    private function ok(callable $check): bool
    {
        try {
            return $check() === true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @template T
     *
     * @param  callable():T  $value
     * @param  T  $fallback
     * @return T
     */
    private function safe(callable $value, mixed $fallback): mixed
    {
        try {
            return $value();
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function tcpReachable(string $host, int $port): bool
    {
        if ($host === '' || $port === 0) {
            return false;
        }

        $connection = @fsockopen($host, $port, $errno, $errstr, 1.0);

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
