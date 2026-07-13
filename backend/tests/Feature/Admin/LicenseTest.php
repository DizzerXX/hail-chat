<?php

namespace Tests\Feature\Admin;

use App\Jobs\LicenseHeartbeatJob;
use App\Models\LicenseState;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LicenseTest extends TestCase
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

    private function configureManager(): void
    {
        config([
            'license.url' => 'https://license.test',
            'license.key' => 'LIC-TDECHAT-123456789',
            'license.platform_slug' => 'tdechat',
            'license.platform_id' => 7,
            'license.platform_access_token' => 'plat_secret',
        ]);
    }

    private function activeState(array $overrides = []): LicenseState
    {
        return LicenseState::create(array_merge([
            'instance_id' => 'instance-1',
            'status' => 'active',
            'type' => 'annual',
            'expires_at' => now()->addYear(),
            'max_users' => 10,
            'features' => ['translation'],
            'activated_at' => now(),
            'last_heartbeat_at' => now(),
            'last_valid_at' => now(),
        ], $overrides));
    }

    public function test_unconfigured_manager_is_disabled_open(): void
    {
        $this->getJson('/api/v1/admin/users')->assertOk();

        $this->getJson('/api/v1/admin/license/seats')
            ->assertOk()
            ->assertJsonPath('data.enforced', false)
            ->assertJsonPath('data.max', null);

        $this->getJson('/api/v1/admin/license')
            ->assertOk()
            ->assertJsonPath('data.configured', false);
    }

    public function test_activation_persists_the_license_payload(): void
    {
        $this->configureManager();

        Http::fake([
            'license.test/api/v1/validate/license' => Http::response([
                'success' => true,
                'message' => 'Activated',
                'data' => ['license' => [
                    'type' => 'annual',
                    'status' => 'active',
                    'expires_at' => now()->addDays(30)->toIso8601String(),
                    'max_users' => 5,
                    'max_instances' => 2,
                    'features' => ['translation', 'reports'],
                    'metadata' => ['customer' => 'Telediant'],
                ]],
            ]),
        ]);

        $this->artisan('license:activate')->assertExitCode(0);

        $state = LicenseState::sole();
        $this->assertSame('active', $state->status);
        $this->assertSame(5, $state->max_users);
        $this->assertSame(['translation', 'reports'], $state->features);
        $this->assertNotEmpty($state->instance_id);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), '/api/v1/validate/license')
                && $data['license_key'] === 'LIC-TDECHAT-123456789'
                && $data['platform_slug'] === 'tdechat'
                && $data['instance_id'] !== '';
        });
    }

    public function test_expired_license_blocks_user_management_with_a_clean_403(): void
    {
        $this->configureManager();
        $this->activeState(['expires_at' => now()->subDay()]);

        $this->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'license_invalid');

        $this->getJson('/api/v1/admin/roles')->assertForbidden();

        // The license screen itself must stay reachable to fix the problem.
        $this->getJson('/api/v1/admin/license')
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        // ...and non-gated admin areas stay open.
        $this->getJson('/api/v1/admin/teams')->assertOk();
    }

    public function test_seat_limit_blocks_creation_and_reactivation(): void
    {
        $this->configureManager();
        $this->activeState(['max_users' => 1]); // the admin already uses the only seat

        $payload = fn (string $email, bool $active) => [
            'name' => 'Seat Test',
            'email' => $email,
            'password' => 'password123',
            'role_id' => Role::where('name', 'agent')->first()->id,
            'is_active' => $active,
        ];

        $this->postJson('/api/v1/admin/users', $payload('one@t.test', true))
            ->assertUnprocessable();

        // Inactive users don't consume seats.
        $created = $this->postJson('/api/v1/admin/users', $payload('two@t.test', false))
            ->assertCreated()
            ->json('data');

        // Re-activating would exceed the cap.
        $this->putJson('/api/v1/admin/users/'.$created['id'], [
            ...$payload('two@t.test', true),
            'password' => null,
        ])->assertUnprocessable();

        $this->getJson('/api/v1/admin/license/seats')
            ->assertOk()
            ->assertJsonPath('data.enforced', true)
            ->assertJsonPath('data.used', 1)
            ->assertJsonPath('data.max', 1);
    }

    public function test_feature_gate_helper(): void
    {
        $this->assertTrue(license()->hasFeature('anything')); // unconfigured → open

        $this->configureManager();
        $this->activeState();

        $this->assertTrue(license()->hasFeature('translation'));
        $this->assertFalse(license()->hasFeature('reports'));
    }

    public function test_unreachable_manager_honors_then_exhausts_the_grace_period(): void
    {
        $this->configureManager();
        $this->activeState(['last_valid_at' => now()]);

        Http::fake(fn () => throw new ConnectionException('timeout'));

        // Heartbeat with the manager down keeps the last known good state.
        (new LicenseHeartbeatJob())->handle(app(\App\Services\License\LicenseService::class));
        $this->assertSame('active', LicenseState::sole()->status);
        $this->getJson('/api/v1/admin/users')->assertOk();

        // Inside the 72h grace window access continues…
        $this->travel(71)->hours();
        $this->getJson('/api/v1/admin/users')->assertOk();

        // …and past it the license goes invalid.
        $this->travel(2)->hours();
        $this->getJson('/api/v1/admin/users')
            ->assertForbidden()
            ->assertJsonPath('code', 'license_invalid');
    }

    public function test_heartbeat_rejection_invalidates_immediately(): void
    {
        $this->configureManager();
        $this->activeState();

        Http::fake([
            'license.test/api/v1/validate/check' => Http::response([
                'success' => false,
                'message' => 'License revoked',
            ], 400),
        ]);

        (new LicenseHeartbeatJob())->handle(app(\App\Services\License\LicenseService::class));

        $this->assertSame('invalid', LicenseState::sole()->status);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_admin_license_screen_payload_and_deactivation(): void
    {
        $this->configureManager();
        $this->activeState(['max_instances' => 2]);

        Http::fake([
            'license.test/api/v1/admin/platforms/licenses' => Http::response([
                'success' => true,
                'data' => ['licenses' => [[
                    'license_key' => 'LIC-TDECHAT-123456789',
                    'status' => 'active',
                    'current_activations' => 1,
                ]]],
            ]),
            'license.test/api/v1/validate/deactivate' => Http::response([
                'success' => true,
                'message' => 'Deactivated',
                'data' => [],
            ]),
        ]);

        $this->getJson('/api/v1/admin/license')
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.activations.used', 1)
            ->assertJsonPath('data.activations.max', 2)
            ->assertJsonPath('data.masked_key', 'LIC-•••••••••••••6789');

        $this->postJson('/api/v1/admin/license/deactivate')->assertOk();
        $this->assertDatabaseCount('license_state', 0);
    }

    public function test_license_endpoints_require_the_license_manage_permission(): void
    {
        $supervisor = User::factory()->for(Role::where('name', 'supervisor')->first())->create();
        Sanctum::actingAs($supervisor);

        $this->getJson('/api/v1/admin/license')->assertForbidden();
        // But the seats banner works for anyone who manages users.
        $this->getJson('/api/v1/admin/license/seats')->assertOk();
    }
}
