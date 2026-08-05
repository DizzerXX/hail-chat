<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Claiming backs the embedded chat window MiCC opens after an agent accepts a
 * work item in Ignite.
 */
class ClaimConversationTest extends TestCase
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

    private function claim(Conversation $conversation): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/claim");
    }

    /**
     * The MiCC case: the poller has already flipped the conversation to active
     * (MiCC ACD'd it) but left it unassigned, so accept() would 409 here.
     */
    public function test_agent_claims_an_active_conversation_left_unassigned_by_the_poller(): void
    {
        $agent = $this->makeAgent();
        $conversation = Conversation::factory()->create([
            'status' => ConversationStatus::Active,
            'assigned_agent_id' => null,
            'accepted_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($agent->user);
        $this->claim($conversation)
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $agent->id)
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'assigned_agent_id' => $agent->id,
            'status' => 'active',
        ]);
    }

    public function test_claiming_a_queued_conversation_accepts_it(): void
    {
        $agent = $this->makeAgent();
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        Sanctum::actingAs($agent->user);
        $this->claim($conversation)
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.assigned_agent.id', $agent->id);

        $this->assertNotNull($conversation->refresh()->accepted_at);
    }

    public function test_claiming_is_idempotent_for_the_owning_agent(): void
    {
        $agent = $this->makeAgent();
        $conversation = Conversation::factory()->active()->create([
            'assigned_agent_id' => $agent->id,
            'accepted_at' => now()->subHour(),
        ]);
        $acceptedAt = $conversation->accepted_at;

        Sanctum::actingAs($agent->user);
        $this->claim($conversation)->assertOk();
        $this->claim($conversation)->assertOk();

        $conversation->refresh();
        $this->assertSame($agent->id, $conversation->assigned_agent_id);
        $this->assertTrue($acceptedAt->equalTo($conversation->accepted_at));
    }

    public function test_conversation_held_by_another_agent_is_not_stolen(): void
    {
        $owner = $this->makeAgent();
        $other = $this->makeAgent();
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $owner->id]);

        Sanctum::actingAs($other->user);
        $this->claim($conversation)->assertStatus(409);

        $this->assertSame($owner->id, $conversation->refresh()->assigned_agent_id);
    }

    public function test_micc_transfer_lets_the_new_agent_take_over_and_logs_it(): void
    {
        // On a MiCC inbox, Ignite is the source of truth for who owns the case,
        // so a regular agent opening the embed after a transfer takes it over.
        $inbox = Inbox::factory()->mitelOpenMedia()->create();
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $conversation = Conversation::factory()->active()->for($inbox)->create(['assigned_agent_id' => $agentA->id]);

        Sanctum::actingAs($agentB->user);
        $this->claim($conversation)
            ->assertOk()
            ->assertJsonPath('data.assigned_agent.id', $agentB->id);

        $this->assertSame($agentB->id, $conversation->refresh()->assigned_agent_id);
        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversation->id,
            'type' => 'transferred',
            'actor_name' => $agentB->displayName(),
        ]);
        $this->assertSame($agentA->displayName(), $conversation->events()->where('type', 'transferred')->first()->detail['from']);
    }

    public function test_a_conversation_abandoned_mid_transfer_stays_with_the_previous_agent(): void
    {
        // Visitor abandoned before agent B claimed → must remain attributed to A,
        // and B cannot retroactively claim a closed/abandoned chat.
        $inbox = Inbox::factory()->mitelOpenMedia()->create();
        $agentA = $this->makeAgent();
        $agentB = $this->makeAgent();
        $conversation = Conversation::factory()->abandoned()->for($inbox)->create(['assigned_agent_id' => $agentA->id]);

        Sanctum::actingAs($agentB->user);
        $this->claim($conversation)->assertStatus(409);

        $this->assertSame($agentA->id, $conversation->refresh()->assigned_agent_id);
    }

    public function test_supervisor_with_assign_permission_may_take_over(): void
    {
        $owner = $this->makeAgent();
        $supervisor = $this->makeAgent('supervisor');
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $owner->id]);

        Sanctum::actingAs($supervisor->user);
        $this->claim($conversation)->assertOk();

        $this->assertSame($supervisor->id, $conversation->refresh()->assigned_agent_id);
    }

    public function test_closed_and_abandoned_conversations_cannot_be_claimed(): void
    {
        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);

        $closed = Conversation::factory()->closed()->create();
        $abandoned = Conversation::factory()->abandoned()->create();

        $this->claim($closed)->assertStatus(409);
        $this->claim($abandoned)->assertStatus(409);
    }

    public function test_claiming_requires_an_agent_profile(): void
    {
        $user = User::factory()->for(Role::where('name', 'admin')->firstOrFail())->create();
        $conversation = Conversation::factory()->create([
            'status' => ConversationStatus::Active,
            'assigned_agent_id' => null,
        ]);

        Sanctum::actingAs($user);
        $this->claim($conversation)->assertStatus(403);
    }

    public function test_agent_cannot_claim_a_conversation_queued_for_another_team(): void
    {
        $agent = $this->makeAgent(teams: [Team::factory()->create()]);
        $conversation = Conversation::factory()->awaitingAcceptance()->create([
            'team_id' => Team::factory()->create()->id,
        ]);

        Sanctum::actingAs($agent->user);
        $this->claim($conversation)->assertStatus(403);
    }
}
