<?php

namespace App\Services\WorkItems\Mitel;

use App\Exceptions\MitelApiException;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Thin wrapper over the MiCC SDK Open Media REST API (v2.0, /miccsdk base
 * path, Bearer authentication). Every [Guessing] endpoint shape lives here,
 * isolated to one method each.
 */
class MitelClient
{
    /**
     * POST /api/v1/openmedia — deliberately no auto-retry: a duplicate POST
     * would create a duplicate work item in MiCC routing.
     */
    public function createWorkItem(array $payload): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->post('/api/v1/openmedia', $payload),
            'create work item',
        );
    }

    public function getWorkItem(string $id): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->retry(2, 200, throw: false)->get('/api/v1/openmedia/'.$id),
            'fetch work item',
        );
    }

    public function putAction(string $id, string $action, array $extra = []): void
    {
        $this->send(
            fn (PendingRequest $http) => $http->put('/api/v1/openmedia/'.$id, ['Action' => $action] + $extra),
            strtolower($action).' work item',
        );
    }

    public function listQueues(): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->retry(2, 200, throw: false)->get('/api/v1/openmedia/queues'),
            'list queues',
        );
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call, string $operation): array
    {
        try {
            $response = $call($this->request());
        } catch (Throwable $exception) {
            throw new MitelApiException(
                "Mitel API: {$operation} failed: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new MitelApiException(
                "Mitel API: {$operation} failed with HTTP {$response->status()}.",
                $response->status(),
            );
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $http = Http::baseUrl(rtrim(config('mitel.base_url'), '/'))
            ->withToken(config('mitel.token'))
            ->acceptJson()
            ->timeout(config('mitel.timeout', 10));

        if (! config('mitel.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }
}
