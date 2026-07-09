<?php

namespace Tests\Feature;

use App\Models\Inbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AgentInboxListTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_can_list_active_inboxes_for_channel_subscriptions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        Inbox::factory()->create(['name' => 'Website']);
        Inbox::factory()->create(['is_active' => false]);

        $agent = User::factory()->for(Role::where('name', 'agent')->first())->create();
        Sanctum::actingAs($agent);

        $this->getJson('/api/v1/agent/inboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Website')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'channel_type']]]);

        $this->getJson('/api/v1/agent/inboxes')->assertOk();
    }

    public function test_requires_view_own_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $nobody = User::factory()->for(Role::create(['name' => 'nobody']))->create();
        Sanctum::actingAs($nobody);

        $this->getJson('/api/v1/agent/inboxes')->assertForbidden();
    }
}
