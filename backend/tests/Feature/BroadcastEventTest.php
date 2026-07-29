<?php

namespace Tests\Feature;

use App\Events\AgentTyping;
use App\Events\VisitorTyping;
use App\Events\ConversationCreated;
use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastEventTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function channelNames(array $channels): array
    {
        return collect($channels)->map(fn (Channel $channel) => (string) $channel->name)->all();
    }

    public function test_starting_a_conversation_broadcasts_conversation_created(): void
    {
        Event::fake([ConversationCreated::class]);

        $inbox = Inbox::factory()->create(['bot_enabled' => false]);

        $uuid = $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Hello',
        ])->json('data.uuid');

        Event::assertDispatched(ConversationCreated::class, function (ConversationCreated $event) use ($uuid, $inbox) {
            return $event->broadcastAs() === 'conversation.created'
                && $this->channelNames($event->broadcastOn()) === [
                    'conversation.'.$uuid,
                    'private-inbox.'.$inbox->id,
                ];
        });
    }

    public function test_visitor_message_broadcasts_message_sent(): void
    {
        Event::fake([MessageSent::class]);

        $conversation = Conversation::factory()->active()->create();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/messages", [
            'body' => 'Ping',
        ])->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($conversation) {
            return $event->broadcastAs() === 'message.sent'
                && $event->message->body === 'Ping'
                && in_array('conversation.'.$conversation->uuid, $this->channelNames($event->broadcastOn()), true);
        });
    }

    public function test_accepting_broadcasts_conversation_updated_including_agent_channel(): void
    {
        Event::fake([ConversationUpdated::class]);
        $this->seed(RolePermissionSeeder::class);

        $agent = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'agent')->first()))
            ->create();
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/accept")->assertOk();

        Event::assertDispatched(ConversationUpdated::class, function (ConversationUpdated $event) use ($conversation, $agent) {
            $channels = $this->channelNames($event->broadcastOn());

            return $event->broadcastAs() === 'conversation.updated'
                && in_array('conversation.'.$conversation->uuid, $channels, true)
                && in_array('private-agents.'.$agent->id, $channels, true);
        });
    }

    public function test_typing_endpoint_broadcasts_agent_typing(): void
    {
        Event::fake([AgentTyping::class]);
        $this->seed(RolePermissionSeeder::class);

        $agent = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'agent')->first()))
            ->create();
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/typing", ['typing' => true])
            ->assertNoContent();

        Event::assertDispatched(AgentTyping::class, function (AgentTyping $event) use ($conversation, $agent) {
            return $event->broadcastAs() === 'agent.typing'
                && $event->typing === true
                && $event->agent->is($agent)
                && $this->channelNames($event->broadcastOn()) === ['conversation.'.$conversation->uuid];
        });
    }

    public function test_public_typing_endpoint_broadcasts_visitor_typing(): void
    {
        Event::fake([VisitorTyping::class]);
        $conversation = Conversation::factory()->active()->create();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/typing", ['typing' => true])
            ->assertNoContent();

        Event::assertDispatched(VisitorTyping::class, function (VisitorTyping $event) use ($conversation) {
            return $event->broadcastAs() === 'visitor.typing'
                && $event->typing === true
                && $this->channelNames($event->broadcastOn()) === ['conversation.'.$conversation->uuid];
        });
    }

    public function test_public_typing_is_ignored_on_a_closed_conversation(): void
    {
        Event::fake([VisitorTyping::class]);
        $conversation = Conversation::factory()->create(['status' => 'closed']);

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/typing", ['typing' => false])
            ->assertNoContent();

        Event::assertNotDispatched(VisitorTyping::class);
    }

    public function test_channel_authorization_rules(): void
    {
        // The phpunit env uses the log broadcaster, whose auth() skips channel
        // callbacks entirely. Switch to the reverb driver and re-register the
        // channel closures on it so authorization is actually exercised.
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');

        $this->seed(RolePermissionSeeder::class);

        $inbox = Inbox::factory()->create();

        $agent = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'agent')->first()))
            ->create();

        $plainRole = Role::create(['name' => 'nobody']);
        $plainUser = User::factory()->for($plainRole)->create();

        $supervisor = User::factory()->for(Role::where('name', 'supervisor')->first())->create();

        // Guests cannot authenticate private channels at all.
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-inbox.'.$inbox->id,
            'socket_id' => '123.456',
        ])->assertUnauthorized();

        // Agents and view_all holders may join inbox channels; others may not.
        Sanctum::actingAs($agent->user);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-inbox.'.$inbox->id,
            'socket_id' => '123.456',
        ])->assertOk();

        Sanctum::actingAs($supervisor);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-inbox.'.$inbox->id,
            'socket_id' => '123.456',
        ])->assertOk();

        Sanctum::actingAs($plainUser);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-inbox.'.$inbox->id,
            'socket_id' => '123.456',
        ])->assertForbidden();

        // Personal agent channel: own id only.
        Sanctum::actingAs($agent->user);
        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-agents.'.$agent->id,
            'socket_id' => '123.456',
        ])->assertOk();

        $this->postJson('/api/broadcasting/auth', [
            'channel_name' => 'private-agents.'.($agent->id + 99),
            'socket_id' => '123.456',
        ])->assertForbidden();
    }
}
