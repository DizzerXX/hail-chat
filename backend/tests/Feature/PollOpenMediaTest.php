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
     * Live-shape per-id responses (camelCase keys, string states) plus the
     * historical $search endpoint for finished items.
     *
     * @param  array<string, array<string, mixed>|null>  $active  id => live item (camelCase)
     * @param  array<string, array<string, mixed>|null>  $history  id => historical item or null
     */
    private function fakeMitel(array $active, array $history = []): void
    {
        Http::fake(function ($request) use ($active, $history) {
            $url = $request->url();

            if (str_contains($url, '%24search=') || str_contains($url, '$search=')) {
                $items = [];
                foreach ($history as $id => $item) {
                    if (str_contains(urldecode($url), $id) && $item !== null) {
                        $items[] = $item;
                    }
                }

                return Http::response(['_embedded' => ['items' => $items]]);
            }

            foreach ($active as $id => $item) {
                if (str_ends_with($url, "/api/v1/openmedia/{$id}")) {
                    return $item === null
                        ? Http::response('', 404)
                        : Http::response($item);
                }
            }

            return Http::response('', 500);
        });
    }

    public function test_live_acd_with_camel_case_string_state_marks_active(): void
    {
        Event::fake([WorkItemAccepted::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-1');
        $this->fakeMitel([
            'wi-1' => ['id' => 'wi-1', 'conversationState' => 'Acd', 'agentName' => 'MiCC Agent', 'agentId' => '42', 'queueName' => 'TDECHAT'],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Active, $conversation->status);
        $this->assertSame('Acd', $conversation->external_state);
        $this->assertNotNull($conversation->accepted_at);
        $this->assertSame('MiCC Agent', $conversation->meta['mitel']['agent_name']);
        $this->assertSame('TDECHAT', $conversation->meta['mitel']['queue_name']);

        Event::assertDispatched(WorkItemAccepted::class);
        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_swagger_integer_states_still_map(): void
    {
        Event::fake([WorkItemAccepted::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-int');
        $this->fakeMitel([
            'wi-int' => ['Id' => 'wi-int', 'ConversationState' => 3, 'AgentName' => 'A'],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
    }

    public function test_unchanged_live_state_updates_meta_without_events(): void
    {
        Event::fake([WorkItemAccepted::class, WorkItemAbandoned::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-2', 'Queued');
        $this->fakeMitel([
            'wi-2' => ['id' => 'wi-2', 'conversationState' => 'Queued', 'positionInQueue' => 4, 'queueName' => 'TDECHAT'],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->status);
        $this->assertSame(4, $conversation->meta['mitel']['position_in_queue']);

        Event::assertNotDispatched(WorkItemAccepted::class);
        Event::assertNotDispatched(ConversationUpdated::class);
    }

    public function test_404_with_ended_history_closes_and_fires_webhook(): void
    {
        $conversation = $this->makeAwaiting('wi-3');
        Webhook::factory()->create([
            'url' => 'https://sink.test/hooks',
            'events' => [WebhookEvent::ConversationClosed->value],
        ]);

        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, '$search=')) {
                return Http::response(['_embedded' => ['items' => [
                    ['id' => 'wi-3', 'conversationState' => 'Ended'],
                ]]]);
            }
            if (str_contains($url, 'sink.test')) {
                return Http::response(['ok' => true]);
            }

            return Http::response('', 404);
        });

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertSame('conversation.closed', WebhookDelivery::sole()->event);
    }

    public function test_404_with_acd_history_means_handled_and_closes(): void
    {
        Event::fake([WorkItemAccepted::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-4');
        $this->fakeMitel(
            ['wi-4' => null],
            ['wi-4' => ['id' => 'wi-4', 'conversationState' => 'Acd', 'agentName' => 'EmailAgent01']],
        );

        dispatch_sync(new PollOpenMediaWorkItems());

        $conversation->refresh();
        // Handled remotely — closed, never re-activated.
        $this->assertSame(ConversationStatus::Closed, $conversation->status);
        $this->assertSame('EmailAgent01', $conversation->meta['mitel']['agent_name']);
        Event::assertNotDispatched(WorkItemAccepted::class);
    }

    public function test_404_with_abandoned_history_marks_abandoned(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $abandoned = $this->makeAwaiting('wi-5');
        $failed = $this->makeAwaiting('wi-6');

        $this->fakeMitel(
            ['wi-5' => null, 'wi-6' => null],
            [
                'wi-5' => ['id' => 'wi-5', 'conversationState' => 'Abandoned'],
                'wi-6' => ['id' => 'wi-6', 'conversationState' => 'InboxFailed'],
            ],
        );

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::Abandoned, $abandoned->fresh()->status);
        $this->assertSame(ConversationStatus::Abandoned, $failed->fresh()->status);
        $this->assertSame('InboxFailed', $failed->fresh()->external_state);
        Event::assertDispatchedTimes(WorkItemAbandoned::class, 2);
    }

    public function test_404_with_empty_history_marks_abandoned_as_gone(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $gone = $this->makeAwaiting('wi-7');
        $this->fakeMitel(['wi-7' => null], ['wi-7' => null]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $gone->refresh();
        $this->assertSame(ConversationStatus::Abandoned, $gone->status);
        $this->assertTrue($gone->meta['mitel']['no_longer_active']);
        Event::assertDispatched(WorkItemAbandoned::class);
    }

    public function test_fresh_items_get_a_routing_grace_before_being_declared_gone(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $fresh = $this->makeAwaiting('wi-fresh');
        $fresh->forceFill([
            'meta' => ['mitel' => ['work_item_created_at' => now()->toIso8601String()]],
        ])->save();

        $this->fakeMitel(['wi-fresh' => null], ['wi-fresh' => null]);

        dispatch_sync(new PollOpenMediaWorkItems());

        // Still routing on the MiCC side — untouched, will be re-polled.
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $fresh->fresh()->status);
        Event::assertNotDispatched(WorkItemAbandoned::class);
    }

    public function test_only_awaiting_mitel_conversations_are_polled(): void
    {
        $this->makeAwaiting('wi-8');
        Conversation::factory()->awaitingAcceptance()->create();
        Conversation::factory()
            ->for(Inbox::factory()->mitelOpenMedia())
            ->active()
            ->create(['external_work_item_id' => 'wi-9', 'external_state' => 'Acd']);

        $this->fakeMitel([
            'wi-8' => ['id' => 'wi-8', 'conversationState' => 'Queued'],
        ]);

        dispatch_sync(new PollOpenMediaWorkItems());

        Http::assertSentCount(1);
    }

    public function test_transient_errors_skip_the_conversation_without_events(): void
    {
        Event::fake([WorkItemAbandoned::class, ConversationUpdated::class]);

        $conversation = $this->makeAwaiting('wi-10');
        Http::fake(['micc.test/*' => Http::response('boom', 500)]);

        dispatch_sync(new PollOpenMediaWorkItems());

        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->fresh()->status);
        Event::assertNotDispatched(WorkItemAbandoned::class);
    }
}
