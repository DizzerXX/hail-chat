<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\License;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Strict per-seat licensing: an agent whose seat has lapsed is blocked from the
 * agent API with a "seat_expired" code (the dashboard turns that into a logout),
 * while non-agents and unconfigured installs are untouched.
 */
class AgentSeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function configurePlatform(): void
    {
        config([
            'license.url' => 'https://license.test',
            'license.platform_id' => 6,
            'license.platform_access_token' => 'plat_secret',
        ]);
    }

    private function makeAgent(): Agent
    {
        $user = User::factory()->for(Role::where('name', 'agent')->first())->create(['is_active' => true]);

        return Agent::factory()->for($user)->create();
    }

    private function grantLicense(Agent $agent, ?string $expiresAt, string $status = 'active'): void
    {
        License::create([
            'platform_license_id' => random_int(1000, 999999),
            'license_key' => 'KEY-'.$agent->id,
            'status' => $status,
            'expires_at' => $expiresAt,
            'assigned_agent_id' => $agent->id,
            'assigned_at' => now(),
        ]);
    }

    public function test_unconfigured_platform_leaves_agents_working(): void
    {
        $agent = $this->makeAgent();
        Sanctum::actingAs($agent->user);

        // No platform configured → disabled-open, even with no license at all.
        $this->getJson('/api/v1/agent/app-config')->assertOk();
    }

    public function test_agent_with_a_usable_seat_is_allowed(): void
    {
        $agent = $this->makeAgent();
        $this->grantLicense($agent, now()->addYear()->toDateTimeString());
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/v1/agent/app-config')->assertOk();
    }

    public function test_agent_with_an_expired_seat_is_blocked_with_seat_expired(): void
    {
        $agent = $this->makeAgent();
        $this->grantLicense($agent, now()->subDay()->toDateTimeString());
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/v1/agent/app-config')
            ->assertStatus(403)
            ->assertJsonPath('code', 'seat_expired');
    }

    public function test_agent_with_an_inactive_seat_is_blocked(): void
    {
        $agent = $this->makeAgent();
        $this->grantLicense($agent, now()->addYear()->toDateTimeString(), status: 'suspended');
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/v1/agent/app-config')
            ->assertStatus(403)
            ->assertJsonPath('code', 'seat_expired');
    }

    public function test_agent_with_no_seat_is_blocked_when_enforced(): void
    {
        $agent = $this->makeAgent();
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/v1/agent/app-config')
            ->assertStatus(403)
            ->assertJsonPath('code', 'seat_expired');
    }

    public function test_an_expired_seat_blocks_sending_a_message(): void
    {
        $agent = $this->makeAgent();
        $this->grantLicense($agent, now()->subDay()->toDateTimeString());
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages", ['body' => 'hi'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'seat_expired');
    }

    public function test_non_agents_are_never_blocked_by_the_seat_gate(): void
    {
        // An admin has no agent profile, so spends no seat — even with the pool
        // exhausted they keep their access to the agent-area endpoints.
        $admin = User::factory()->for(Role::where('name', 'admin')->first())->create();
        $this->configurePlatform();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/agent/app-config')->assertOk();
    }

    public function test_auth_me_reports_the_seat_status(): void
    {
        $agent = $this->makeAgent();
        $this->grantLicense($agent, now()->subDay()->toDateTimeString());
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        // /auth/me is not seat-gated, so the dashboard can read the flag and
        // log the agent out gracefully.
        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.agent.licensed', false);
    }

    public function test_auth_me_reports_licensed_true_for_a_usable_seat(): void
    {
        $agent = $this->makeAgent();
        $this->grantLicense($agent, now()->addYear()->toDateTimeString());
        $this->configurePlatform();
        Sanctum::actingAs($agent->user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.agent.licensed', true);
    }
}
