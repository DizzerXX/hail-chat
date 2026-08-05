<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->for(Role::where('name', 'admin')->firstOrFail())->create();
    }

    public function test_login_is_audited(): void
    {
        $user = User::factory()->for(Role::where('name', 'agent')->firstOrFail())->create([
            'email' => 'agent@example.com', 'password' => bcrypt('secret123'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'agent@example.com', 'password' => 'secret123', 'device_name' => 'test',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_creating_and_deactivating_a_user_is_audited(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/users', [
            'name' => 'New Agent', 'email' => 'new@example.com', 'password' => 'password123',
            'role_id' => Role::where('name', 'agent')->value('id'), 'is_active' => true,
        ])->assertCreated();

        $userId = $response->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'user.created', 'subject_id' => $userId]);

        $this->putJson("/api/v1/admin/users/{$userId}", [
            'name' => 'New Agent', 'email' => 'new@example.com',
            'role_id' => Role::where('name', 'agent')->value('id'), 'is_active' => false,
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'user.deactivated', 'subject_id' => $userId]);
    }

    public function test_the_view_endpoints_return_details_and_activity(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin);

        // Give the admin an audit entry to show up in their own activity.
        $this->putJson('/api/v1/admin/system-settings', [
            'app_name' => 'X', 'retention_enabled' => false, 'retention_days' => 180,
        ])->assertOk();

        $this->getJson("/api/v1/admin/users/{$admin->id}")
            ->assertOk()
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonStructure(['data' => ['last_login_at']]);

        $this->getJson("/api/v1/admin/users/{$admin->id}/activity")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'settings.updated');
    }
}
