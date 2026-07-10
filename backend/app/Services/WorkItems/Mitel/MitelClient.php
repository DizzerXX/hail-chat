<?php

namespace App\Services\WorkItems\Mitel;

use App\Enums\MitelWorkItemAction;
use App\Exceptions\MitelApiException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
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
    public function __construct(private MitelTokenProvider $tokens)
    {
    }

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

    /**
     * OData list of items currently being executed (InQueue/ACD only).
     * Items in Failed/Handled states are absent from this list.
     */
    public function listWorkItems(int $top = 1000): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http
                ->retry(2, 200, $this->onlyConnectionErrors(), throw: false)
                ->get('/api/v1/openmedia', ['$top' => $top]),
            'list work items',
        );

        // Swagger examples show "Items" but real MCX servers emit "items".
        return $response['_embedded']['Items']
            ?? $response['_embedded']['items']
            ?? (array_is_list($response) ? $response : []);
    }

    /**
     * Returns details solely while the item is in an InQueue or ACD state;
     * Failed/Handled items respond 404 (resolve those via searchWorkItem).
     */
    public function getWorkItem(string $id): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http
                ->retry(2, 200, $this->onlyConnectionErrors(), throw: false)
                ->get('/api/v1/openmedia/'.$id),
            'fetch work item',
        );
    }

    /**
     * OData historical lookup — returns the item with its final state even
     * after it left the active list. Null when nothing matches.
     */
    public function searchWorkItem(string $id): ?array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http
                ->retry(2, 200, $this->onlyConnectionErrors(), throw: false)
                ->get('/api/v1/openmedia', ['$search' => "'{$id}'"]),
            'search work item',
        );

        $items = $response['_embedded']['items'] ?? $response['_embedded']['Items'] ?? [];

        foreach ($items as $item) {
            if (($item['Id'] ?? $item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    public function putAction(string $id, MitelWorkItemAction $action): void
    {
        $this->send(
            fn (PendingRequest $http) => $http->put('/api/v1/openmedia/'.$id, ['Action' => $action->value]),
            strtolower($action->name).' work item',
        );
    }

    public function listQueues(): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http
                ->retry(2, 200, $this->onlyConnectionErrors(), throw: false)
                ->get('/api/v1/openmedia/queues'),
            'list queues',
        );
    }

    /**
     * Transport-level retries are for flaky networks only; HTTP error
     * statuses (incl. 401) must reach send() so token refresh can act.
     */
    private function onlyConnectionErrors(): Closure
    {
        return fn (Throwable $exception) => $exception instanceof ConnectionException;
    }

    /**
     * @param  Closure(PendingRequest): Response  $call
     */
    private function send(Closure $call, string $operation): array
    {
        $attempts = 0;

        do {
            $attempts++;

            try {
                $response = $call($this->request());
            } catch (Throwable $exception) {
                throw new MitelApiException(
                    "Mitel API: {$operation} failed: {$exception->getMessage()}",
                    previous: $exception,
                );
            }

            // A 401 means the cached token died early (revocation, MiCC
            // restart): drop it and retry once with a fresh one.
            if ($response->status() === 401 && $attempts === 1) {
                $this->tokens->forget();

                continue;
            }

            break;
        } while ($attempts < 2);

        if (! $response->successful()) {
            $body = trim((string) $response->body());

            throw new MitelApiException(
                "Mitel API: {$operation} failed with HTTP {$response->status()}."
                    .($body !== '' ? ' Response: '.mb_substr($body, 0, 300) : ''),
                $response->status(),
            );
        }

        return $response->json() ?? [];
    }

    private function request(): PendingRequest
    {
        $http = Http::baseUrl(rtrim(config('mitel.base_url'), '/'))
            ->withToken($this->tokens->getToken())
            ->acceptJson()
            ->timeout(config('mitel.timeout', 10));

        if (! config('mitel.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }
}
