<?php

namespace App\Services\WorkItems;

use App\Contracts\PollableProviderInterface;
use App\Contracts\QueueDirectoryProviderInterface;
use App\Contracts\WorkItemProviderInterface;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MitelConversationState;
use App\Enums\MitelWorkItemAction;
use App\Enums\SenderType;
use App\Exceptions\MitelApiException;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Team;
use App\Services\WorkItems\Mitel\MitelClient;
use App\Services\WorkItems\Mitel\WorkItemSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mirrors conversation lifecycle onto MiCC Open Media work items. Calls the
 * Mitel API first and mutates local rows only after success, so a failed
 * call never desyncs local state.
 */
class MitelOpenMediaProvider implements PollableProviderInterface, QueueDirectoryProviderInterface, WorkItemProviderInterface
{
    public function __construct(private MitelClient $client)
    {
    }

    public function createWorkItem(Conversation $conversation): void
    {
        $conversation->loadMissing('inbox');

        $response = $this->client->createWorkItem($this->buildCreatePayload($conversation));

        $workItemId = $response['Id'] ?? $response['id'] ?? null;

        if (! is_string($workItemId) || $workItemId === '') {
            throw new MitelApiException('Mitel API: create work item response did not contain an Id.');
        }

        $state = MitelConversationState::fromResponse(
            $response['ConversationState'] ?? $response['conversationState'] ?? null,
        );

        $conversation->forceFill([
            'external_work_item_id' => $workItemId,
            'external_state' => $state->name,
            'status' => ConversationStatus::AwaitingAcceptance,
            'meta' => $this->mergeMitelMeta($conversation, [
                'queue_name' => $response['QueueName'] ?? $response['queueName'] ?? null,
                'position_in_queue' => $response['PositionInQueue'] ?? $response['positionInQueue'] ?? null,
                // Anchors the routing grace window: MiCC can take ~10s to
                // route a fresh item, during which per-id GETs 404 and the
                // historical record is still empty.
                'work_item_created_at' => now()->toIso8601String(),
            ]),
        ])->save();
    }

    public function completeWorkItem(Conversation $conversation): void
    {
        if ($conversation->external_work_item_id !== null) {
            $this->client->putAction($conversation->external_work_item_id, MitelWorkItemAction::Complete);
        }

        $conversation->status = ConversationStatus::Closed;
        $conversation->closed_at = now();
        $conversation->save();
    }

    /**
     * The OpenMediaUpdateRequestModel carries only the Action — MiCC
     * re-runs its own routing. Team/agent arguments retarget local
     * metadata only.
     */
    public function rerouteWorkItem(Conversation $conversation, ?Team $team = null, ?Agent $agent = null): void
    {
        if ($conversation->external_work_item_id !== null) {
            $this->client->putAction($conversation->external_work_item_id, MitelWorkItemAction::Reroute);
        }

        $conversation->forceFill([
            'team_id' => $team?->id,
            'assigned_agent_id' => $agent?->id,
            'accepted_at' => null,
            'status' => ConversationStatus::AwaitingAcceptance,
        ])->save();
    }

    /**
     * Per-id polling (live-verified): GET /openmedia/{id} answers 200 while
     * the item is InQueue/ACD and 404 once it reaches a Failed/Handled
     * state. On 404, the historical OData $search lookup recovers the FINAL
     * state (Ended vs Abandoned vs InboxFailed) so transitions map exactly.
     * The plain active list is unreliable here — MCX filters it by device
     * visibility, so pending items may never appear in it.
     *
     * @return Collection<int, WorkItemSnapshot>
     */
    public function pollPendingWorkItems(): Collection
    {
        return Conversation::query()
            ->where('status', ConversationStatus::AwaitingAcceptance)
            ->whereNotNull('external_work_item_id')
            ->whereHas('inbox', fn (Builder $query) => $query->where('channel_type', ChannelType::MitelOpenMedia))
            ->get()
            ->map(function (Conversation $conversation) {
                try {
                    return WorkItemSnapshot::fromResponse(
                        $conversation,
                        $this->client->getWorkItem($conversation->external_work_item_id),
                    );
                } catch (MitelApiException $exception) {
                    if ($exception->statusCode !== 404) {
                        Log::warning('Mitel work-item poll failed; skipping conversation.', [
                            'conversation' => $conversation->uuid,
                            'work_item' => $conversation->external_work_item_id,
                            'error' => $exception->getMessage(),
                        ]);

                        return null;
                    }
                }

                return $this->resolveTerminalState($conversation);
            })
            ->filter()
            ->values();
    }

    /**
     * @return list<array{id: string|null, name: string|null}>
     */
    public function listQueues(): array
    {
        return Cache::remember(
            'mitel.queues',
            config('mitel.queues_cache_ttl', 60),
            function () {
                $raw = $this->client->listQueues();

                // Live-verified shape: {_links, count, _embedded: {items: [{id, name, reporting}]}}
                // (swagger example capitalizes "Items"; real MCX servers do not)
                $items = $raw['_embedded']['items']
                    ?? $raw['_embedded']['Items']
                    ?? (array_is_list($raw) ? $raw : []);

                return collect($items)
                    ->map(fn (array $queue) => [
                        'id' => isset($queue['id']) ? (string) $queue['id'] : (isset($queue['Id']) ? (string) $queue['Id'] : null),
                        'name' => $queue['name'] ?? $queue['Name'] ?? null,
                    ])
                    ->values()
                    ->all();
            },
        );
    }

    /**
     * The item 404'd off the realtime endpoint — pull its final state from
     * the historical record; fall back to a missing-marker when even the
     * history has nothing.
     */
    private function resolveTerminalState(Conversation $conversation): WorkItemSnapshot
    {
        try {
            $historical = $this->client->searchWorkItem($conversation->external_work_item_id);
        } catch (MitelApiException $exception) {
            Log::warning('Mitel historical lookup failed for a finished work item.', [
                'conversation' => $conversation->uuid,
                'work_item' => $conversation->external_work_item_id,
                'error' => $exception->getMessage(),
            ]);

            $historical = null;
        }

        return $historical === null
            ? WorkItemSnapshot::missing($conversation)
            : WorkItemSnapshot::fromResponse($conversation, $historical, terminal: true);
    }

    private function buildCreatePayload(Conversation $conversation): array
    {
        $mitelConfig = $conversation->inbox->channel_config['mitel'] ?? [];

        $firstMessage = $conversation->messages()
            ->where('sender_type', SenderType::Visitor)
            ->orderBy('id')
            ->first();

        $variableData = array_filter([
            'Name' => $conversation->visitor_name,
            'Email' => $conversation->visitor_email,
            'Phone' => $conversation->visitor_phone,
        ], fn (?string $value) => $value !== null && $value !== '');

        return array_filter([
            'TargetUri' => $this->targetUri($conversation),
            'TargetUriEmbedded' => true,
            'Queue' => $mitelConfig['queue'] ?? null,
            'From' => $conversation->visitor_email ?? $conversation->visitor_name,
            'Subject' => $firstMessage !== null ? Str::limit($firstMessage->body, 80) : null,
            'Tenant' => config('mitel.tenant') ?: null,
            'VariableData' => $variableData !== [] ? $variableData : null,
        ], fn (mixed $value) => $value !== null);
    }

    private function targetUri(Conversation $conversation): string
    {
        // The embed route is the chat window alone — no rail, no conversation
        // list — which is what belongs inside the Ignite agent desktop.
        $template = config('mitel.agent_chat_url')
            ?: rtrim(config('app.url'), '/').'/embed/chat/{uuid}';

        return str_replace('{uuid}', $conversation->uuid, $template);
    }

    private function mergeMitelMeta(Conversation $conversation, array $mitel): array
    {
        return array_merge($conversation->meta ?? [], [
            'mitel' => array_filter($mitel, fn (mixed $value) => $value !== null),
        ]);
    }
}
