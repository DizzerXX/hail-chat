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
