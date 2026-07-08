<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Enums\WebhookEvent;
use App\Events\ConversationUpdated;
use App\Events\WorkItemAbandoned;
use App\Events\WorkItemAccepted;
use App\Jobs\PollOpenMediaWorkItems;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PollOpenMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mitel.base_url' => 'https://micc.test/miccsdk',
            'mitel.client_id' => 'tdechat',
            'mitel.client_secret' => 'someservicesecret',
        ]);

        Cache::put('mitel.access_token', 'secret-token');
    }

    private function makeAwaiting(string $workItemId, string $state = 'Queued'): Conversation
    {
        return Conversation::factory()
            ->for(Inbox::factory()->mitelOpenMedia())
            ->awaitingAcceptance()
            ->create([
                'external_work_item_id' => $workItemId,
                'external_state' => $state,
            ]);
    }

    private function fakeWorkItem(string $workItemId, int $state, array $extra = []): void
    {
        Http::fake([
            "micc.test/miccsdk/api/v1/openmedia/$workItemId" => Http::response(
                array_merge(['Id' => $workItemId, 'ConversationState' => $state], $extra),
            ),
        ]);
    }

    public function test_acd_marks_the_conversation_active(): void
    {
        Event::fake([WorkItemAccepted::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-1');
        $this->fakeWorkItem('wi-1', 3, ['AgentName' => 'MiCC Agent', 'AgentId' => '42']);

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Active, $conversation->status);
        $this->assertSame('Acd', $conversation->external_state);
        $this->assertNotNull($conversation->accepted_at);
        $this->assertSame('MiCC Agent', $conversation->meta['mitel']['agent_name']);

        Event::assertDispatched(WorkItemAccepted::class, fn ($event) => $event->conversation->is($conversation));
        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_abandoned_and_exited_queue_mark_abandoned(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $abandoned = $this->makeAwaiting('wi-2');
        $exited = $this->makeAwaiting('wi-3');

        Http::fake([
            'micc.test/miccsdk/api/v1/openmedia/wi-2' => Http::response(['Id' => 'wi-2', 'ConversationState' => 7]),
            'micc.test/miccsdk/api/v1/openmedia/wi-3' => Http::response(['Id' => 'wi-3', 'ConversationState' => 8]),
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Abandoned, $abandoned->fresh()->status);
        $this->assertSame(ConversationStatus::Abandoned, $exited->fresh()->status);
        $this->assertSame('ExitedQueue', $exited->fresh()->external_state);
        $this->assertNotNull($abandoned->fresh()->closed_at);

        Event::assertDispatchedTimes(WorkItemAbandoned::class, 2);
    }

    public function test_ended_closes_and_fires_the_closed_webhook(): void
    {
        $conversation = $this->makeAwaiting('wi-4');
        Webhook::factory()->create([
            'url' => 'https://sink.test/hooks',
            'events' => [WebhookEvent::ConversationClosed->value],
        ]);

        Http::fake([
            'micc.test/miccsdk/api/v1/openmedia/wi-4' => Http::response(['Id' => 'wi-4', 'ConversationState' => 6]),
            'sink.test/*' => Http::response(['ok' => true]),
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);

        $delivery = WebhookDelivery::sole();
        $this->assertSame('conversation.closed', $delivery->event);
        $this->assertSame('system', $delivery->payload['data']['closed_by']);
    }

    public function test_inbox_failed_marks_abandoned(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-5');
        $this->fakeWorkItem('wi-5', 11);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Abandoned, $conversation->fresh()->status);
        $this->assertSame('InboxFailed', $conversation->fresh()->external_state);
        Event::assertDispatched(WorkItemAbandoned::class);
    }

    public function test_unchanged_state_updates_meta_without_events(): void
    {
        Event::fake([WorkItemAccepted::class, WorkItemAbandoned::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-6', 'Queued');
        $this->fakeWorkItem('wi-6', 9, ['PositionInQueue' => 1, 'QueueName' => 'OpenMediaQ']);

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->status);
        $this->assertSame(1, $conversation->meta['mitel']['position_in_queue']);

        Event::assertNotDispatched(WorkItemAccepted::class);
        Event::assertNotDispatched(WorkItemAbandoned::class);
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    public function test_only_awaiting_mitel_conversations_are_polled(): void
    {
        Http::fake(['micc.test/*' => Http::response(['Id' => 'x', 'ConversationState' => 9])]);

        $this->makeAwaiting('wi-7');
        // Standalone awaiting — no external id, standalone inbox.
        Conversation::factory()->awaitingAcceptance()->create();
        // Mitel but already active.
        Conversation::factory()
            ->for(Inbox::factory()->mitelOpenMedia())
            ->active()
            ->create(['external_work_item_id' => 'wi-8', 'external_state' => 'Acd']);

        dispatch_sync(new PollOpenMediaWorkItems());

        Http::assertSentCount(1);
    }

    public function test_one_failing_work_item_does_not_sink_the_batch(): void
    {
        Event::fake([WorkItemAccepted::class, ConversationUpdated::class]);

        $failing = $this->makeAwaiting('wi-bad');
        $healthy = $this->makeAwaiting('wi-good');

        Http::fake([
            'micc.test/miccsdk/api/v1/openmedia/wi-bad' => Http::response('boom', 500),
            'micc.test/miccsdk/api/v1/openmedia/wi-good' => Http::response(['Id' => 'wi-good', 'ConversationState' => 3]),
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::AwaitingAcceptance, $failing->fresh()->status);
        $this->assertSame(ConversationStatus::Active, $healthy->fresh()->status);
    }
}
