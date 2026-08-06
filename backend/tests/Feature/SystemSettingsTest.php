<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SystemSettings;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        SystemSettings::forgetCurrent();
    }

    private function admin(): User
    {
        return User::factory()->for(Role::where('name', 'admin')->firstOrFail())->create();
    }

    private function agent(): User
    {
        return User::factory()->for(Role::where('name', 'agent')->firstOrFail())->create();
    }

    public function test_public_app_config_exposes_the_brand(): void
    {
        SystemSettings::current()->update(['app_name' => 'NIISE Livechat']);
        SystemSettings::forgetCurrent();

        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.brand.app_name', 'NIISE Livechat');
    }

    public function test_admin_can_read_and_update_system_settings(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/system-settings')
            ->assertOk()
            ->assertJsonPath('data.app_name', 'TDEChat');

        $this->putJson('/api/v1/admin/system-settings', [
            'app_name' => 'NIISE Livechat',
            'favicon' => null,
            'logo' => null,
            'retention_enabled' => true,
            'retention_days' => 90,
        ])->assertOk()->assertJsonPath('data.app_name', 'NIISE Livechat');

        $this->assertDatabaseHas('system_settings', ['app_name' => 'NIISE Livechat', 'retention_days' => 90]);
    }

    public function test_system_settings_require_the_manage_permission(): void
    {
        Sanctum::actingAs($this->agent());

        $this->getJson('/api/v1/admin/system-settings')->assertStatus(403);
        $this->putJson('/api/v1/admin/system-settings', [])->assertStatus(403);
    }

    public function test_retention_days_are_floored(): void
    {
        Sanctum::actingAs($this->admin());

        $this->putJson('/api/v1/admin/system-settings', [
            'app_name' => 'X',
            'retention_enabled' => true,
            'retention_days' => 1,        // below the min floor
        ])->assertStatus(422)->assertJsonValidationErrors('retention_days');
    }

    public function test_status_endpoint_returns_a_health_snapshot(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/v1/admin/system/status')
            ->assertOk()
            ->assertJsonPath('data.database', true)          // the test DB is reachable
            ->assertJsonPath('data.version', config('app.version'))
            ->assertJsonStructure(['data' => ['version', 'redis', 'reverb', 'queue' => ['up', 'pending'], 'mitel', 'license']]);
    }
}
