<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()
            ->for(Role::where('name', 'agent')->first())
            ->create($attributes);
    }

    public function test_login_returns_working_token_and_user(): void
    {
        $user = $this->makeUser(['email' => 'aina@tdechat.test']);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'aina@tdechat.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', 'aina@tdechat.test')
            ->assertJsonPath('user.role', 'agent');

        $this->assertContains('messages.send', $response->json('user.permissions'));

        $token = $response->json('token');

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_login_rejects_wrong_password(): void
    {
        $this->makeUser(['email' => 'aina@tdechat.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'aina@tdechat.test',
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ])->assertUnprocessable();
    }

    public function test_login_rejects_deactivated_user(): void
    {
        $this->makeUser(['email' => 'aina@tdechat.test', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'aina@tdechat.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->assertForbidden();
    }

    public function test_logout_revokes_the_token(): void
    {
        $this->makeUser(['email' => 'aina@tdechat.test']);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'aina@tdechat.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->json('token');

        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // The auth manager caches the resolved user within a single app
        // instance; clear it so the next request re-authenticates from DB.
        $this->app->make('auth')->forgetGuards();

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertUnauthorized();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_token_past_the_absolute_cap_is_rejected(): void
    {
        config(['sanctum.expiration' => 720]); // 12h

        $this->makeUser(['email' => 'aina@tdechat.test']);
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'aina@tdechat.test',
            'password' => 'password',
            'device_name' => 'phpunit',
        ])->json('token');

        // Fresh token works.
        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->app->make('auth')->forgetGuards();

        // Age it past the window — Sanctum then rejects it as expired.
        PersonalAccessToken::query()->update(['created_at' => now()->subMinutes(721)]);

        $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }
}
