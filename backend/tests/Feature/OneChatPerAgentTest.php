<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\User;
use App\Services\RoutingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An agent handles one conversation at a time. Mitel inboxes are exempt —
 * MiCC does its own routing and capacity control.
 */
class OneChatPerAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAgent(string $role = 'agent'): Agent
    {
        $user = User::factory()->for(Role::where('name', $role)->firstOrFail())->create();

        return Agent::factory()->for($user)->create();
    }

    public function test_agent_cannot_accept_a_second_conversation(): void
    {
        $agent = $this->makeAgent();
        Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);
        $queued = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$queued->uuid}/accept")
            ->assertStatus(409)
            ->assertJsonPath('message', 'You are already handling a conversation. Close it before taking another.');

        $this->assertNull($queued->fresh()->assigned_agent_id);
    }

    public function test_agent_can_accept_once_the_first_conversation_is_closed(): void
    {
        $agent = $this->makeAgent();
        $first = Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);
        $second = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$first->uuid}/close")->assertOk();
        $this->postJson("/api/v1/agent/conversations/{$second->uuid}/accept")->assertOk();

        $this->assertSame($agent->id, $second->fresh()->assigned_agent_id);
    }

    public function test_queued_and_closed_conversations_do_not_occupy_an_agent(): void
    {
        $agent = $this->makeAgent();
        // Offered but not accepted, plus a finished one: neither is "handling".
        Conversation::factory()->awaitingAcceptance()->create(['assigned_agent_id' => $agent->id]);
        Conversation::factory()->closed()->create(['assigned_agent_id' => $agent->id]);
        $queued = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$queued->uuid}/accept")->assertOk();
    }

    public function test_mitel_conversations_are_exempt_because_micc_controls_capacity(): void
    {
        $agent = $this->makeAgent();
        Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);

        $mitel = Inbox::factory()->create(['channel_type' => ChannelType::MitelOpenMedia]);
        $work = Conversation::factory()->for($mitel)->create([
            'status' => ConversationStatus::Active,
            'assigned_agent_id' => null,
        ]);

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$work->uuid}/claim")->assertOk();

        $this->assertSame($agent->id, $work->fresh()->assigned_agent_id);
    }

    public function test_claiming_a_standalone_conversation_while_busy_is_refused(): void
    {
        $agent = $this->makeAgent();
        Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);
        $queued = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$queued->uuid}/claim")->assertStatus(409);
    }

    public function test_rerouting_to_a_busy_agent_is_refused(): void
    {
        $supervisor = $this->makeAgent('supervisor');
        $busy = $this->makeAgent();
        Conversation::factory()->active()->create(['assigned_agent_id' => $busy->id]);
        $queued = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($supervisor->user);
        $this->postJson("/api/v1/agent/conversations/{$queued->uuid}/reroute", [
            'agent_id' => $busy->id,
        ])->assertStatus(409);

        $this->assertNull($queued->fresh()->assigned_agent_id);
    }

    public function test_routing_rules_skip_a_busy_agent_and_leave_the_conversation_queued(): void
    {
        $agent = $this->makeAgent();
        $inbox = Inbox::factory()->create();

        // A rule targets a team OR an agent, never both (schema check
        // constraint), so skipping a busy agent falls to the general queue.
        RoutingRule::factory()->for($inbox)->create([
            'agent_id' => $agent->id,
            'team_id' => null,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
        ]);

        Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);

        $conversation = Conversation::factory()->for($inbox)->create();
        app(RoutingService::class)->route($conversation, 'hello');

        $this->assertNull($conversation->assigned_agent_id);
        $this->assertNull($conversation->team_id);
    }

    public function test_routing_rules_still_target_a_free_agent(): void
    {
        $agent = $this->makeAgent();
        $inbox = Inbox::factory()->create();

        RoutingRule::factory()->for($inbox)->create([
            'agent_id' => $agent->id,
            'team_id' => null,
            'priority' => 1,
            'is_active' => true,
            'conditions' => [],
        ]);

        $conversation = Conversation::factory()->for($inbox)->create();
        app(RoutingService::class)->route($conversation, 'hello');

        $this->assertSame($agent->id, $conversation->assigned_agent_id);
    }
}
