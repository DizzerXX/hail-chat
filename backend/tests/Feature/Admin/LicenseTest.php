<?php

namespace Tests\Feature\Admin;

use App\Models\Agent;
use App\Models\License;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Licenses are a pool synced from the platform and spent one per agent.
 * Http::fake stands in for the platform as a transport double only.
 */
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

    private function configurePlatform(): void
    {
        config([
            'license.url' => 'https://license.test',
            'license.platform_slug' => 'tdechat',
            'license.platform_id' => 6,
            'license.platform_access_token' => 'plat_secret',
        ]);
    }

    /** @var list<array<string, mixed>> */
    private array $platformLicenses = [];

    private bool $platformFaked = false;

    /**
     * Http::fake() appends stubs rather than replacing them, so registering it
     * twice would leave the first response winning. Register once and let the
     * stubs read mutable state instead.
     *
     * @param  list<array<string, mixed>>  $licenses
     */
    private function fakePlatform(array $licenses): void
    {
        $this->platformLicenses = $licenses;

        if ($this->platformFaked) {
            return;
        }

        $this->platformFaked = true;

        Http::fake([
            'https://license.test/api/v1/admin/platforms/sync-licenses' => fn () => Http::response([
                'success' => true,
                'message' => 'Successfully synced '.count($this->platformLicenses).' licenses',
                'data' => [
                    'platform' => ['id' => 6, 'slug' => 'tdechat'],
                    'sync_time' => now()->toIso8601String(),
                    'synced_count' => count($this->platformLicenses),
                    'synced_licenses' => $this->platformLicenses,
                ],
            ]),
            'https://license.test/api/v1/admin/platforms/licenses' => fn () => Http::response([
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'platform' => ['id' => 6, 'slug' => 'tdechat'],
                    'licenses' => $this->platformLicenses,
                    'total_licenses' => count($this->platformLicenses),
                ],
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return list<array<string, mixed>>
     */
    private function platformLicenses(int $count, array $overrides = []): array
    {
        return collect(range(1, $count))
            ->map(fn (int $i) => array_merge([
                'id' => 100 + $i,
                'license_key' => 'KEY-000'.$i.'-ABCDEFGH',
                'status' => 'active',
                'type' => 'premium',
                'customer_company' => 'Telediant',
                'issued_at' => now()->subDay()->toIso8601String(),
                'expires_at' => now()->addYear()->toIso8601String(),
                'activation_date' => now()->toIso8601String(),
                'max_users' => 100,
                'max_instances' => 1,
                'features' => ['translation'],
                'metadata' => ['bulk_generated' => true],
            ], $overrides))
            ->all();
    }

    private function makeAgent(string $email): Agent
    {
        $user = User::factory()
            ->for(Role::where('name', 'agent')->first())
            ->create(['email' => $email, 'is_active' => true]);

        return Agent::factory()->for($user)->create();
    }

    public function test_unconfigured_platform_leaves_everything_open(): void
    {
        $this->getJson('/api/v1/admin/license')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        $this->getJson('/api/v1/admin/users')->assertOk();
        $this->assertTrue(license()->canActivateAgent());
        $this->assertTrue(license()->hasFeature('anything'));
    }

    public function test_sync_stores_licenses_with_their_detail(): void
    {
        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(3));

        $this->postJson('/api/v1/admin/license/sync')
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonCount(3, 'data.licenses');

        $license = License::orderBy('platform_license_id')->first();
        $this->assertSame(101, $license->platform_license_id);
        $this->assertSame('active', $license->status);
        // Detail the sync response omits comes from the platform listing.
        $this->assertSame('premium', $license->type);
        $this->assertSame(['translation'], $license->features);
        $this->assertNotNull($license->last_synced_at);
    }

    public function test_sync_is_idempotent_and_updates_statuses(): void
    {
        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(2));
        license()->sync();

        $this->fakePlatform($this->platformLicenses(2, ['status' => 'suspended']));
        $result = license()->sync();

        $this->assertSame(2, License::count(), 'licenses must upsert, not duplicate');
        $this->assertSame(0, $result['added']);
        $this->assertSame('suspended', License::first()->status);
    }

    public function test_first_sync_assigns_existing_agents_oldest_first(): void
    {
        $first = $this->makeAgent('first@tdechat.test');
        $second = $this->makeAgent('second@tdechat.test');

        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(3));

        $result = license()->sync();

        $this->assertSame(2, $result['assigned']);
        $this->assertSame($first->id, License::orderBy('platform_license_id')->first()->assigned_agent_id);
        $this->assertNotNull($second->fresh()->license);
        $this->assertSame(2, license()->seatsUsed());
        $this->assertSame(1, license()->freeSeats());
    }

    public function test_creating_an_agent_takes_a_license_and_the_pool_runs_out(): void
    {
        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(1));
        license()->sync();

        $payload = fn (string $email) => [
            'name' => 'Agent '.$email,
            'email' => $email,
            'password' => 'password123',
            'role_id' => Role::where('name', 'agent')->first()->id,
            'agent' => ['display_name' => 'A', 'max_concurrent_chats' => 5],
        ];

        $this->postJson('/api/v1/admin/users', $payload('one@tdechat.test'))->assertCreated();
        $this->assertSame(1, license()->seatsUsed());

        $this->postJson('/api/v1/admin/users', $payload('two@tdechat.test'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'No licenses available: 1 of 1 in use. Sync the license platform, add licenses, or release one from another agent.');

        // The rejected user must not have been created.
        $this->assertDatabaseMissing('users', ['email' => 'two@tdechat.test']);
    }

    public function test_a_user_without_an_agent_profile_uses_no_license(): void
    {
        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(1));
        license()->sync();

        $this->postJson('/api/v1/admin/users', [
            'name' => 'Supervisor',
            'email' => 'sup@tdechat.test',
            'password' => 'password123',
            'role_id' => Role::where('name', 'supervisor')->first()->id,
        ])->assertCreated();

        $this->assertSame(0, license()->seatsUsed());
    }

    public function test_deactivating_an_agent_keeps_their_license(): void
    {
        $agent = $this->makeAgent('holder@tdechat.test');

        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(1));
        license()->sync();

        $this->putJson("/api/v1/admin/users/{$agent->user->id}", [
            'name' => $agent->user->name,
            'email' => $agent->user->email,
            'role_id' => $agent->user->role_id,
            'is_active' => false,
        ])->assertOk();

        $this->assertSame($agent->id, License::first()->assigned_agent_id);
        $this->assertSame(1, license()->seatsUsed());
    }

    public function test_releasing_a_license_returns_it_to_the_pool(): void
    {
        $agent = $this->makeAgent('holder@tdechat.test');

        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(1));
        license()->sync();

        $license = License::first();
        $this->assertSame($agent->id, $license->assigned_agent_id);

        $this->postJson("/api/v1/admin/licenses/{$license->id}/release")
            ->assertOk()
            ->assertJsonPath('data.seats.used', 0)
            ->assertJsonPath('data.seats.free', 1);

        $this->assertNull($license->fresh()->assigned_agent_id);
    }

    public function test_expired_and_inactive_licenses_do_not_count_as_capacity(): void
    {
        $this->configurePlatform();
        $this->fakePlatform([
            ...$this->platformLicenses(1, ['status' => 'inactive']),
            [
                'id' => 200,
                'license_key' => 'KEY-EXPIRED-ZZZZ',
                'status' => 'active',
                'expires_at' => now()->subDay()->toIso8601String(),
                'features' => [],
            ],
        ]);
        license()->sync();

        $this->assertSame(0, license()->capacity());
        $this->assertFalse(license()->isValid());
        $this->assertFalse(license()->canActivateAgent());

        // User management is gated, but the license screen stays reachable.
        $this->getJson('/api/v1/admin/users')
            ->assertStatus(403)
            ->assertJsonPath('code', 'license_invalid');
        $this->getJson('/api/v1/admin/license')->assertOk();
    }

    public function test_features_come_from_usable_licenses_only(): void
    {
        $this->configurePlatform();
        $this->fakePlatform($this->platformLicenses(1, ['features' => ['translation']]));
        license()->sync();

        $this->assertTrue(license()->hasFeature('translation'));
        $this->assertFalse(license()->hasFeature('sms'));
    }

    public function test_license_management_requires_the_permission(): void
    {
        $supervisor = User::factory()->for(Role::where('name', 'supervisor')->first())->create();
        Sanctum::actingAs($supervisor);

        $this->getJson('/api/v1/admin/license')->assertStatus(403);
        // Seats power the Users screen banner, so agents.manage is enough.
        $this->getJson('/api/v1/admin/license/seats')->assertOk();
    }
}
