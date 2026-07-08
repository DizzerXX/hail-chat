<?php

namespace App\Services\Webhooks;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\SendWebhookJob;
use App\Models\Inbox;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Builder;

class WebhookDispatcher
{
    /**
     * Queue one delivery per active webhook subscribed to the event.
     * Inbox-scoped webhooks only fire for their inbox; global webhooks
     * (inbox_id null) fire for everything.
     */
    public function dispatch(WebhookEvent $event, array $data, ?Inbox $inbox = null): void
    {
        $webhooks = Webhook::query()
            ->where('is_active', true)
            ->whereJsonContains('events', $event->value)
            ->when(
                $inbox,
                fn (Builder $query) => $query->where(
                    fn (Builder $query) => $query->whereNull('inbox_id')->orWhere('inbox_id', $inbox->id)
                ),
                fn (Builder $query) => $query->whereNull('inbox_id'),
            )
            ->get();

        foreach ($webhooks as $webhook) {
            $delivery = $webhook->deliveries()->create([
                'event' => $event->value,
                'payload' => [
                    'event' => $event->value,
                    'timestamp' => now()->toIso8601String(),
                    'data' => $data,
                ],
                'status' => DeliveryStatus::Pending,
            ]);

            SendWebhookJob::dispatch($delivery);
        }
    }
}
