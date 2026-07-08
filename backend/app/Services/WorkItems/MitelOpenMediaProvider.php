<?php

namespace App\Services\WorkItems;

use App\Contracts\PollableProviderInterface;
use App\Contracts\QueueDirectoryProviderInterface;
use App\Contracts\WorkItemProviderInterface;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\MitelConversationState;
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

        $workItemId = $response['Id'] ?? null;

        if (! is_string($workItemId) || $workItemId === '') {
            throw new MitelApiException('Mitel API: create work item response did not contain an Id.');
        }

        $state = MitelConversationState::fromResponse($response['ConversationState'] ?? null);

        $conversation->forceFill([
            'external_work_item_id' => $workItemId,
            'external_state' => $state->name,
            'status' => ConversationStatus::AwaitingAcceptance,
            'meta' => $this->mergeMitelMeta($conversation, [
                'queue_name' => $response['QueueName'] ?? null,
                'position_in_queue' => $response['PositionInQueue'] ?? null,
            ]),
        ])->save();
    }

    public function completeWorkItem(Conversation $conversation): void
    {
        if ($conversation->external_work_item_id !== null) {
            $this->client->putAction($conversation->external_work_item_id, 'Complete');
        }

        $conversation->status = ConversationStatus::Closed;
        $conversation->closed_at = now();
        $conversation->save();
    }

    public function rerouteWorkItem(Conversation $conversation, ?Team $team = null, ?Agent $agent = null): void
    {
        $extra = [];
        $teamQueues = $conversation->inbox->channel_config['mitel']['team_queues'] ?? [];

        if ($team !== null && isset($teamQueues[(string) $team->id])) {
            $extra['Queue'] = $teamQueues[(string) $team->id];
        }

        if ($conversation->external_work_item_id !== null) {
            $this->client->putAction($conversation->external_work_item_id, 'Reroute', $extra);
        }

        $conversation->forceFill([
            'team_id' => $team?->id,
            'assigned_agent_id' => $agent?->id,
            'accepted_at' => null,
            'status' => ConversationStatus::AwaitingAcceptance,
        ])->save();
    }

    /**
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
                    $data = $this->client->getWorkItem($conversation->external_work_item_id);
                } catch (MitelApiException $exception) {
                    Log::warning('Mitel work-item poll failed; skipping conversation.', [
                        'conversation' => $conversation->uuid,
                        'work_item' => $conversation->external_work_item_id,
                        'error' => $exception->getMessage(),
                    ]);

                    return null;
                }

                return WorkItemSnapshot::fromResponse($conversation, $data);
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

                $items = array_is_list($raw)
                    ? $raw
                    : ($raw['_embedded']['queues'] ?? $raw['Queues'] ?? $raw['items'] ?? []);

                return collect($items)
                    ->map(fn (array $queue) => [
                        'id' => isset($queue['Id']) ? (string) $queue['Id'] : ($queue['id'] ?? null),
                        'name' => $queue['Name'] ?? $queue['name'] ?? null,
                    ])
                    ->values()
                    ->all();
            },
        );
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
            'Tenant' => config('mitel.tenant'),
            'VariableData' => $variableData !== [] ? $variableData : null,
        ], fn (mixed $value) => $value !== null);
    }

    private function targetUri(Conversation $conversation): string
    {
        $template = config('mitel.agent_chat_url')
            ?: rtrim(config('app.url'), '/').'/agent/chat/{uuid}';

        return str_replace('{uuid}', $conversation->uuid, $template);
    }

    private function mergeMitelMeta(Conversation $conversation, array $mitel): array
    {
        return array_merge($conversation->meta ?? [], [
            'mitel' => array_filter($mitel, fn (mixed $value) => $value !== null),
        ]);
    }
}
