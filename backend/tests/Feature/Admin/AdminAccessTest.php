<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/inboxes')->assertUnauthorized();
        $this->getJson('/api/v1/admin/analytics')->assertUnauthorized();
    }

    public function test_permission_gates_per_admin_area(): void
    {
        $agent = User::factory()->for(Role::where('name', 'agent')->first())->create();
        Sanctum::actingAs($agent);

        // Plain agents hold none of the admin permissions.
        $this->getJson('/api/v1/admin/inboxes')->assertForbidden();
        $this->getJson('/api/v1/admin/webhooks')->assertForbidden();
        $this->getJson('/api/v1/admin/analytics')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();

        // Supervisors: teams/agents/faqs/analytics but not inboxes/webhooks/routing.
        $supervisor = User::factory()->for(Role::where('name', 'supervisor')->first())->create();
        Sanctum::actingAs($supervisor);

        $this->getJson('/api/v1/admin/teams')->assertOk();
        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->getJson('/api/v1/admin/faqs')->assertOk();
        $this->getJson('/api/v1/admin/analytics')->assertOk();
        $this->getJson('/api/v1/admin/inboxes')->assertForbidden();
        $this->getJson('/api/v1/admin/webhooks')->assertForbidden();

        // Admin: everything.
        $admin = User::factory()->for(Role::where('name', 'admin')->first())->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/admin/inboxes')->assertOk();
        $this->getJson('/api/v1/admin/webhooks')->assertOk();
    }
}
