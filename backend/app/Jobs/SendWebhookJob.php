<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Initial attempt + three retries. */
    public int $tries = 4;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public WebhookDelivery $delivery)
    {
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        $delivery = $this->delivery->fresh(['webhook']);

        if ($delivery === null || $delivery->webhook === null || $delivery->status === DeliveryStatus::Success) {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    'X-TDEChat-Event' => $delivery->event,
                    'X-TDEChat-Delivery' => (string) $delivery->id,
                    'X-TDEChat-Signature' => 'sha256='.hash_hmac('sha256', $body, $delivery->webhook->secret),
                ])
                ->timeout(10)
                ->post($delivery->webhook->url);

            if ($response->successful()) {
                $delivery->update([
                    'status' => DeliveryStatus::Success,
                    'attempts' => $delivery->attempts + 1,
                    'last_status_code' => $response->status(),
                    'last_error' => null,
                    'next_attempt_at' => null,
                    'completed_at' => now(),
                ]);

                return;
            }

            $this->recordFailure($delivery, $response->status(), Str::limit($response->body(), 1000));
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, null, Str::limit($exception->getMessage(), 1000));
        }
    }

    private function recordFailure(WebhookDelivery $delivery, ?int $statusCode, string $error): void
    {
        $attempts = $delivery->attempts + 1;
        $isFinal = $attempts >= $this->tries;
        $delay = $this->backoff()[$attempts - 1] ?? 600;

        $delivery->update([
            'attempts' => $attempts,
            'last_status_code' => $statusCode,
            'last_error' => $error,
            'status' => $isFinal ? DeliveryStatus::Failed : DeliveryStatus::Pending,
            'next_attempt_at' => $isFinal ? null : now()->addSeconds($delay),
            'completed_at' => $isFinal ? now() : null,
        ]);

        if (! $isFinal && $this->job !== null) {
            $this->release($delay);
        }
    }
}
