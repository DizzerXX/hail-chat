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

    /**
     * Fake the OData active-items list (InQueue/ACD items only).
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function fakeActiveList(array $items): void
    {
        Http::fake([
            'micc.test/miccsdk/api/v1/openmedia?*' => Http::response([
                '_links' => '',
                'count' => count($items),
                '_embedded' => ['Items' => $items],
            ]),
        ]);
    }

    public function test_acd_in_the_active_list_marks_the_conversation_active(): void
    {
        Event::fake([WorkItemAccepted::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-1');
        $this->fakeActiveList([
            ['Id' => 'wi-1', 'ConversationState' => 3, 'AgentName' => 'MiCC Agent', 'AgentId' => '42'],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Active, $conversation->status);
        $this->assertSame('Acd', $conversation->external_state);
        $this->assertNotNull($conversation->accepted_at);
        $this->assertSame('MiCC Agent', $conversation->meta['mitel']['agent_name']);

        Event::assertDispatched(WorkItemAccepted::class, fn ($event) => $event->conversation->is($conversation));
        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_terminal_states_reported_in_the_list_are_mapped(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $abandoned = $this->makeAwaiting('wi-2');
        $exited = $this->makeAwaiting('wi-3');
        $failed = $this->makeAwaiting('wi-5');

        $this->fakeActiveList([
            ['Id' => 'wi-2', 'ConversationState' => 7],
            ['Id' => 'wi-3', 'ConversationState' => 8],
            ['Id' => 'wi-5', 'ConversationState' => 11],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Abandoned, $abandoned->fresh()->status);
        $this->assertSame(ConversationStatus::Abandoned, $exited->fresh()->status);
        $this->assertSame(ConversationStatus::Abandoned, $failed->fresh()->status);
        $this->assertSame('ExitedQueue', $exited->fresh()->external_state);
        $this->assertSame('InboxFailed', $failed->fresh()->external_state);
        $this->assertNotNull($abandoned->fresh()->closed_at);

        Event::assertDispatchedTimes(WorkItemAbandoned::class, 3);
    }

    public function test_missing_from_the_active_list_marks_abandoned(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $gone = $this->makeAwaiting('wi-gone');
        $this->fakeActiveList([
            ['Id' => 'wi-other', 'ConversationState' => 9],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $gone->refresh();
        $this->assertSame(ConversationStatus::Abandoned, $gone->status);
        $this->assertNotNull($gone->closed_at);
        $this->assertTrue($gone->meta['mitel']['no_longer_active']);
        // Last observed state is preserved, not overwritten.
        $this->assertSame('Queued', $gone->external_state);

        Event::assertDispatched(WorkItemAbandoned::class);
    }

    public function test_ended_in_the_list_closes_and_fires_the_closed_webhook(): void
    {
        $conversation = $this->makeAwaiting('wi-4');
        Webhook::factory()->create([
            'url' => 'https://sink.test/hooks',
            'events' => [WebhookEvent::ConversationClosed->value],
        ]);

        Http::fake([
            'micc.test/miccsdk/api/v1/openmedia?*' => Http::response([
                '_embedded' => ['Items' => [['Id' => 'wi-4', 'ConversationState' => 6]]],
            ]),
            'sink.test/*' => Http::response(['ok' => true]),
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);

        $delivery = WebhookDelivery::sole();
        $this->assertSame('conversation.closed', $delivery->event);
        $this->assertSame('system', $delivery->payload['data']['closed_by']);
    }

    public function test_unchanged_state_updates_meta_without_events(): void
    {
        Event::fake([WorkItemAccepted::class, WorkItemAbandoned::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-6', 'Queued');
        $this->fakeActiveList([
            ['Id' => 'wi-6', 'ConversationState' => 9, 'PositionInQueue' => 1, 'QueueName' => 'OpenMediaQ'],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->status);
        $this->assertSame(1, $conversation->meta['mitel']['position_in_queue']);

        Event::assertNotDispatched(WorkItemAccepted::class);
        Event::assertNotDispatched(WorkItemAbandoned::class);
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    public function test_one_list_call_covers_all_pending_conversations(): void
    {
        $first = $this->makeAwaiting('wi-7');
        $second = $this->makeAwaiting('wi-8');
        // Not polled: standalone awaiting, and mitel already active.
        Conversation::factory()->awaitingAcceptance()->create();
        Conversation::factory()
            ->for(Inbox::factory()->mitelOpenMedia())
            ->active()
            ->create(['external_work_item_id' => 'wi-9', 'external_state' => 'Acd']);

        $this->fakeActiveList([
            ['Id' => 'wi-7', 'ConversationState' => 9],
            ['Id' => 'wi-8', 'ConversationState' => 1],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        Http::assertSentCount(1);
        $this->assertSame('Offered', $second->fresh()->external_state);
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $first->fresh()->status);
    }

    public function test_no_http_at_all_when_nothing_is_pending(): void
    {
        Http::fake();

        Conversation::factory()->awaitingAcceptance()->create();

        dispatch_sync(new PollOpenMediaWorkItems());

        Http::assertSentCount(0);
    }

    public function test_list_failure_skips_the_pass_without_touching_conversations(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-10');
        Http::fake(['micc.test/*' => Http::response('boom', 500)]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->fresh()->status);
        Event::assertNotDispatched(WorkItemAbandoned::class);
    }
}
