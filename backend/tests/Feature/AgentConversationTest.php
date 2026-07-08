<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAgent(string $role = 'agent', array $teams = []): Agent
    {
        $user = User::factory()->for(Role::where('name', $role)->firstOrFail())->create();
        $agent = Agent::factory()->for($user)->create();

        foreach ($teams as $team) {
            $agent->teams()->attach($team);
        }

        return $agent;
    }

    public function test_queue_scope_lists_only_conversations_visible_to_the_agent(): void
    {
        $myTeam = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $me = $this->makeAgent(teams: [$myTeam]);

        $general = Conversation::factory()->awaitingAcceptance()->create();
        $forMyTeam = Conversation::factory()->awaitingAcceptance()->create(['team_id' => $myTeam->id]);
        $forOtherTeam = Conversation::factory()->awaitingAcceptance()->create(['team_id' => $otherTeam->id]);
        $offeredToMe = Conversation::factory()->awaitingAcceptance()->create(['assigned_agent_id' => $me->id]);
        $offeredToSomeoneElse = Conversation::factory()->awaitingAcceptance()->create([
            'assigned_agent_id' => Agent::factory()->create()->id,
        ]);
        $active = Conversation::factory()->active()->create();

        Sanctum::actingAs($me->user);
        $response = $this->getJson('/api/v1/agent/conversations?scope=queue')->assertOk();

        $uuids = collect($response->json('data'))->pluck('uuid');

        $this->assertEqualsCanonicalizing(
            [$general->uuid, $forMyTeam->uuid, $offeredToMe->uuid],
            $uuids->all(),
        );
    }

    public function test_mine_scope_lists_conversations_assigned_to_me(): void
    {
        $me = $this->makeAgent();
        $mine = Conversation::factory()->active()->create(['assigned_agent_id' => $me->id]);
        Conversation::factory()->active()->create();

        Sanctum::actingAs($me->user);
        $response = $this->getJson('/api/v1/agent/conversations?scope=mine')->assertOk();

        $this->assertSame([$mine->uuid], collect($response->json('data'))->pluck('uuid')->all());
    }

    public function test_all_scope_requires_view_all_permission(): void
    {
        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);
        $this->getJson('/api/v1/agent/conversations?scope=all')->assertForbidden();

        $supervisor = $this->makeAgent('supervisor');
        Sanctum::actingAs($supervisor->user);
        $this->getJson('/api/v1/agent/conversations?scope=all')->assertOk();
    }

    public function test_accept_assigns_and_activates(): void
    {
        $me = $this->makeAgent();
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($me->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.assigned_agent.id', $me->id);

        $this->assertNotNull($conversation->fresh()->accepted_at);

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/accept")
            ->assertStatus(409);
    }

    public function test_accept_enforces_team_targeting(): void
    {
        $team = Team::factory()->create();
        $outsider = $this->makeAgent();
        $conversation = Conversation::factory()->awaitingAcceptance()->create(['team_id' => $team->id]);

        Sanctum::actingAs($outsider->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/accept")->assertForbidden();

        $supervisor = $this->makeAgent('supervisor');
        Sanctum::actingAs($supervisor->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/accept")->assertOk();
    }

    public function test_only_the_assignee_can_send_messages_unless_assign_permission(): void
    {
        $assignee = $this->makeAgent();
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $assignee->id]);

        Sanctum::actingAs($assignee->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages", ['body' => 'Hello!'])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'agent')
            ->assertJsonPath('data.agent.id', $assignee->id);

        $other = $this->makeAgent();
        Sanctum::actingAs($other->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages", ['body' => 'Hi'])
            ->assertForbidden();

        $supervisor = $this->makeAgent('supervisor');
        Sanctum::actingAs($supervisor->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages", ['body' => 'Supervising'])
            ->assertCreated();
    }

    public function test_messages_require_an_active_conversation(): void
    {
        $me = $this->makeAgent();
        $conversation = Conversation::factory()->awaitingAcceptance()->create(['assigned_agent_id' => $me->id]);

        Sanctum::actingAs($me->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages", ['body' => 'Too early'])
            ->assertStatus(409);
    }

    public function test_close_by_assignee_and_forbidden_for_others(): void
    {
        $assignee = $this->makeAgent();
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $assignee->id]);

        $other = $this->makeAgent();
        Sanctum::actingAs($other->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/close")->assertForbidden();

        Sanctum::actingAs($assignee->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
        ]);
    }

    public function test_reroute_requires_assign_permission_and_one_target(): void
    {
        $conversation = Conversation::factory()->active()->create();
        $team = Team::factory()->create();

        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/reroute", ['team_id' => $team->id])
            ->assertForbidden();

        $supervisor = $this->makeAgent('supervisor');
        Sanctum::actingAs($supervisor->user);

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/reroute", [
            'team_id' => $team->id,
            'agent_id' => $agent->id,
        ])->assertUnprocessable();

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/reroute", ['team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_acceptance')
            ->assertJsonPath('data.team.id', $team->id)
            ->assertJsonPath('data.assigned_agent', null);
    }

    public function test_show_enforces_visibility(): void
    {
        $me = $this->makeAgent();
        $foreign = Conversation::factory()->active()->create();

        Sanctum::actingAs($me->user);
        $this->getJson("/api/v1/agent/conversations/{$foreign->uuid}")->assertForbidden();

        $supervisor = $this->makeAgent('supervisor');
        Sanctum::actingAs($supervisor->user);
        $this->getJson("/api/v1/agent/conversations/{$foreign->uuid}")->assertOk();
    }

    public function test_status_update_and_missing_agent_profile(): void
    {
        $me = $this->makeAgent();
        Sanctum::actingAs($me->user);

        $this->putJson('/api/v1/agent/status', ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.status', 'available');

        $plainUser = User::factory()->for(Role::where('name', 'agent')->first())->create();
        Sanctum::actingAs($plainUser);
        $this->putJson('/api/v1/agent/status', ['status' => 'available'])->assertForbidden();
    }

    public function test_agent_routes_require_authentication_and_permission(): void
    {
        $this->getJson('/api/v1/agent/conversations')->assertUnauthorized();

        $noPermissionRole = Role::create(['name' => 'nobody']);
        $user = User::factory()->for($noPermissionRole)->create();

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/agent/conversations')->assertForbidden();

        $conversation = Conversation::factory()->awaitingAcceptance()->create();
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/accept")->assertForbidden();
    }

    public function test_status_filter_narrows_results(): void
    {
        $me = $this->makeAgent();
        Conversation::factory()->active()->create(['assigned_agent_id' => $me->id]);
        Conversation::factory()->closed()->create(['assigned_agent_id' => $me->id]);

        Sanctum::actingAs($me->user);

        $response = $this->getJson('/api/v1/agent/conversations?scope=mine&status=closed')->assertOk();

        $this->assertSame(
            [ConversationStatus::Closed->value],
            collect($response->json('data'))->pluck('status')->unique()->values()->all(),
        );
    }
}
