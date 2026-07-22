<?php

namespace Tests\Feature\Admin;

use App\Models\Agent;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTeamAndUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->for(Role::where('name', 'admin')->first())->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_team_crud_and_membership_sync(): void
    {
        $team = $this->postJson('/api/v1/admin/teams', ['name' => 'Billing'])
            ->assertCreated()
            ->json('data');

        $agents = Agent::factory()->count(2)->create();

        $this->putJson("/api/v1/admin/teams/{$team['id']}/members", [
            'agent_ids' => $agents->pluck('id')->all(),
        ])->assertOk()->assertJsonCount(2, 'data.agents');

        $this->putJson("/api/v1/admin/teams/{$team['id']}/members", [
            'agent_ids' => [$agents->first()->id],
        ])->assertOk()->assertJsonCount(1, 'data.agents');

        $this->putJson("/api/v1/admin/teams/{$team['id']}", ['name' => 'Billing MY', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Billing MY')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/admin/teams/{$team['id']}")->assertNoContent();
    }

    public function test_user_creation_with_agent_profile(): void
    {
        $team = Team::factory()->create();
        $agentRole = Role::where('name', 'agent')->first();

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'Aina Agent',
            'email' => 'aina@tdechat.test',
            'password' => 'password123',
            'role_id' => $agentRole->id,
            'agent' => [
                'display_name' => 'Aina',
                'team_ids' => [$team->id],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'agent')
            ->assertJsonPath('data.agent.display_name', 'Aina')
            ->assertJsonPath('data.agent.team_ids.0', $team->id);

        // The new account can actually log in.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'aina@tdechat.test',
            'password' => 'password123',
            'device_name' => 'test',
        ])->assertOk();
    }

    public function test_user_update_can_remove_agent_profile(): void
    {
        $user = User::factory()->for(Role::where('name', 'agent')->first())->create();
        Agent::factory()->for($user)->create();

        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id,
            'agent' => null,
        ])->assertOk()->assertJsonPath('data.agent', null);

        $this->assertDatabaseMissing('agents', ['user_id' => $user->id]);
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        $this->putJson("/api/v1/admin/users/{$this->admin->id}", [
            'name' => $this->admin->name,
            'email' => $this->admin->email,
            'role_id' => $this->admin->role_id,
            'is_active' => false,
        ])->assertStatus(422);
    }

    public function test_user_search_and_role_filter(): void
    {
        User::factory()->for(Role::where('name', 'agent')->first())->create(['name' => 'Zulkifli Rahman']);

        $this->getJson('/api/v1/admin/users?search=zulkifli')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Zulkifli Rahman');

        $this->getJson('/api/v1/admin/users?role=supervisor')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_roles_listing(): void
    {
        $this->getJson('/api/v1/admin/roles')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_agent_directory(): void
    {
        Agent::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/agents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'display_name', 'user_name', 'status', 'team_ids']]]);
    }
}
