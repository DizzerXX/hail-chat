<?php

namespace App\Jobs;

use App\Enums\ConversationStatus;
use App\Enums\MitelConversationState;
use App\Enums\WebhookEvent;
use App\Events\ConversationUpdated;
use App\Events\WorkItemAbandoned;
use App\Events\WorkItemAccepted;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\WorkItems\Mitel\WorkItemSnapshot;
use App\Services\WorkItems\MitelOpenMediaProvider;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Syncs MiCC work-item state onto conversations awaiting acceptance.
 * Conversations naturally drop out of polling once they leave the
 * awaiting_acceptance status (Acd/Ended/Abandoned/…).
 */
class PollOpenMediaWorkItems implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(MitelOpenMediaProvider $provider, WebhookDispatcher $webhooks): void
    {
        foreach ($provider->pollPendingWorkItems() as $snapshot) {
            $conversation = Conversation::with('inbox')->find($snapshot->conversationId);

            if ($conversation === null || $conversation->status !== ConversationStatus::AwaitingAcceptance) {
                continue;
            }

            if ($snapshot->missing) {
                if ($this->withinRoutingGrace($conversation)) {
                    continue;
                }

                $this->markGone($conversation, $snapshot);

                continue;
            }

            $stateChanged = $conversation->external_state !== $snapshot->state->name;

            $conversation->forceFill([
                'external_state' => $snapshot->state->name,
                'meta' => array_merge($conversation->meta ?? [], [
                    'mitel' => array_filter([
                        'queue_name' => $snapshot->queueName,
                        'position_in_queue' => $snapshot->positionInQueue,
                        'agent_id' => $snapshot->agentId,
                        'agent_name' => $snapshot->agentName,
                    ], fn (mixed $value) => $value !== null),
                ]),
            ])->save();

            if ($snapshot->terminal) {
                // The item already left the realtime endpoint; its historical
                // state decides the outcome. Acd/Ended here mean it was
                // handled — never "activate" a finished conversation.
                match ($snapshot->state) {
                    MitelConversationState::Acd,
                    MitelConversationState::Ended => $this->markEnded($conversation, $webhooks),
                    MitelConversationState::InboxFailed,
                    MitelConversationState::InboxFailedReply => $this->markAbandoned($conversation, $snapshot, failed: true),
                    default => $this->markAbandoned($conversation, $snapshot),
                };

                continue;
            }

            if (! $stateChanged) {
                continue;
            }

            match ($snapshot->state) {
                MitelConversationState::Acd => $this->markAccepted($conversation, $snapshot),
                MitelConversationState::Abandoned,
                MitelConversationState::ExitedQueue => $this->markAbandoned($conversation, $snapshot),
                MitelConversationState::InboxFailed,
                MitelConversationState::InboxFailedReply => $this->markAbandoned($conversation, $snapshot, failed: true),
                MitelConversationState::Ended => $this->markEnded($conversation, $webhooks),
                default => null,
            };
        }
    }

    private function markAccepted(Conversation $conversation, WorkItemSnapshot $snapshot): void
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Active,
            'accepted_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        event(new WorkItemAccepted($conversation, $snapshot));
        broadcast(new ConversationUpdated($conversation));
    }

    /**
     * Freshly created items 404 on the realtime endpoint AND have no
     * historical record while MiCC's workflow routes them (~10s observed
     * live) — don't declare them gone until the grace window has passed.
     */
    private function withinRoutingGrace(Conversation $conversation): bool
    {
        $createdAt = $conversation->meta['mitel']['work_item_created_at'] ?? null;

        if (! is_string($createdAt)) {
            return false;
        }

        $grace = (int) config('mitel.routing_grace_seconds', 60);

        return now()->diffInSeconds($createdAt, true) < $grace;
    }

    /**
     * The item is on neither the realtime endpoint nor the historical
     * record after the routing grace — it ended without an agent.
     */
    private function markGone(Conversation $conversation, WorkItemSnapshot $snapshot): void
    {
        $wasWith = $conversation->assignedAgent?->displayName();

        $conversation->forceFill([
            'status' => ConversationStatus::Abandoned,
            'closed_at' => now(),
            'meta' => array_merge($conversation->meta ?? [], [
                'mitel' => array_merge($conversation->meta['mitel'] ?? [], ['no_longer_active' => true]),
            ]),
        ])->save();

        $conversation->recordEvent('abandoned', 'system', null, array_filter(['reason' => 'no_agent', 'was_with' => $wasWith]));

        event(new WorkItemAbandoned($conversation, $snapshot));
        broadcast(new ConversationUpdated($conversation));
    }

    private function markAbandoned(Conversation $conversation, WorkItemSnapshot $snapshot, bool $failed = false): void
    {
        $wasWith = $conversation->assignedAgent?->displayName();

        $conversation->forceFill([
            'status' => ConversationStatus::Abandoned,
            'closed_at' => now(),
        ])->save();

        $conversation->recordEvent('abandoned', 'system', null, array_filter([
            'reason' => $failed ? 'inbox_failed' : 'micc',
            'was_with' => $wasWith,
        ]));

        if ($failed) {
            Log::warning('MiCC reported an inbox routing failure for a work item.', [
                'conversation' => $conversation->uuid,
                'work_item' => $conversation->external_work_item_id,
                'state' => $snapshot->state->name,
            ]);
        }

        event(new WorkItemAbandoned($conversation, $snapshot));
        broadcast(new ConversationUpdated($conversation));
    }

    private function markEnded(Conversation $conversation, WebhookDispatcher $webhooks): void
    {
        $conversation->forceFill([
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
        ])->save();

        $conversation->recordEvent('closed', 'system', $conversation->assignedAgent?->displayName(), ['by' => 'micc']);

        $webhooks->dispatch(
            WebhookEvent::ConversationClosed,
            [
                'conversation' => ConversationResource::make(
                    $conversation->loadMissing(['inbox', 'assignedAgent', 'team'])
                )->resolve(),
                'closed_by' => 'system',
            ],
            $conversation->inbox,
        );

        broadcast(new ConversationUpdated($conversation));
    }
}
