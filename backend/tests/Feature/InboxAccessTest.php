<?php

namespace Tests\Feature;

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
 * An inbox belongs to one or more teams, and only those teams' agents work it.
 * This is separate from a conversation's team_id, which routes and assigns.
 */
class InboxAccessTest extends TestCase
{
    use RefreshDatabase;

    private Team $eAduan;

    private Team $pertanyaan;

    private Inbox $eAduanInbox;

    private Inbox $pertanyaanInbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->eAduan = Team::factory()->create(['name' => 'eAduan']);
        $this->pertanyaan = Team::factory()->create(['name' => 'Pertanyaan']);

        $this->eAduanInbox = Inbox::factory()->create(['name' => 'eAduan']);
        $this->eAduanInbox->teams()->sync([$this->eAduan->id]);

        $this->pertanyaanInbox = Inbox::factory()->create(['name' => 'Pertanyaan']);
        $this->pertanyaanInbox->teams()->sync([$this->pertanyaan->id]);
    }

    private function agentOf(Team $team): Agent
    {
        $agent = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'agent')->first()))
            ->create();
        $agent->teams()->sync([$team->id]);

        return $agent;
    }

    /** Queued, unassigned, no team on the conversation — the open case. */
    private function queuedIn(Inbox $inbox): Conversation
    {
        return Conversation::factory()->create([
            'inbox_id' => $inbox->id,
            'status' => 'awaiting_acceptance',
            'assigned_agent_id' => null,
            'team_id' => null,
        ]);
    }

    public function test_the_queue_only_shows_conversations_from_the_agents_own_inboxes(): void
    {
        $mine = $this->queuedIn($this->eAduanInbox);
        $this->queuedIn($this->pertanyaanInbox);

        Sanctum::actingAs($this->agentOf($this->eAduan)->user);

        $this->getJson('/api/v1/agent/conversations?scope=queue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $mine->uuid);
    }

    public function test_opening_a_conversation_from_another_teams_inbox_is_forbidden(): void
    {
        $theirs = $this->queuedIn($this->pertanyaanInbox);

        Sanctum::actingAs($this->agentOf($this->eAduan)->user);

        $this->getJson("/api/v1/agent/conversations/{$theirs->uuid}")
            ->assertForbidden();
    }

    public function test_an_agent_sees_a_conversation_once_their_team_holds_the_inbox(): void
    {
        $conversation = $this->queuedIn($this->pertanyaanInbox);
        $agent = $this->agentOf($this->eAduan);

        Sanctum::actingAs($agent->user);
        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")->assertForbidden();

        $this->pertanyaanInbox->teams()->attach($this->eAduan->id);

        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")->assertOk();
    }

    public function test_view_all_holders_are_not_scoped_by_inbox(): void
    {
        $this->queuedIn($this->eAduanInbox);
        $this->queuedIn($this->pertanyaanInbox);

        $supervisor = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'supervisor')->first()))
            ->create();
        $supervisor->teams()->sync([$this->eAduan->id]);
        Sanctum::actingAs($supervisor->user);

        $this->getJson('/api/v1/agent/conversations?scope=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_agent_inbox_list_is_scoped_too(): void
    {
        Sanctum::actingAs($this->agentOf($this->eAduan)->user);

        $this->getJson('/api/v1/agent/inboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'eAduan');
    }

    /**
     * Only reachable for rows predating inbox access, or when the last holding
     * team is deleted and cascades the pivot. Open beats silently stranded.
     */
    public function test_an_inbox_with_no_teams_stays_visible_to_everyone(): void
    {
        $orphan = Inbox::factory()->create(['name' => 'Legacy']);
        $conversation = $this->queuedIn($orphan);

        Sanctum::actingAs($this->agentOf($this->eAduan)->user);

        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")->assertOk();
    }

    public function test_creating_an_inbox_without_a_team_is_rejected(): void
    {
        $admin = User::factory()->for(Role::where('name', 'admin')->first())->create();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/inboxes', [
            'name' => 'No team',
            'slug' => 'no-team',
            'channel_type' => 'standalone',
        ])->assertUnprocessable()->assertJsonValidationErrors('team_ids');
    }
}
