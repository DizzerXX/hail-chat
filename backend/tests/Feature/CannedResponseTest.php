<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\CannedResponse;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CannedResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->for(Role::where('name', $role)->first())->create();
        if ($role === 'agent') {
            Agent::factory()->for($user)->create();
        }
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_admin_can_crud_canned_responses(): void
    {
        $this->actAs('admin');

        $id = $this->postJson('/api/v1/admin/canned-responses', [
            'title' => 'Greeting',
            'body' => 'Hello, how can I help?',
            'language' => 'en',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/canned-responses/{$id}", [
            'title' => 'Greeting',
            'body' => 'Hi there!',
            'language' => 'en',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('data.body', 'Hi there!');

        $this->getJson('/api/v1/admin/canned-responses')->assertOk()->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/admin/canned-responses/{$id}")->assertNoContent();
        $this->assertDatabaseCount('canned_responses', 0);
    }

    public function test_an_agent_cannot_manage_canned_responses(): void
    {
        $this->actAs('agent');

        $this->getJson('/api/v1/admin/canned-responses')->assertStatus(403);
        $this->postJson('/api/v1/admin/canned-responses', ['title' => 'x', 'body' => 'y'])
            ->assertStatus(403);
    }

    public function test_agent_list_filters_by_language_and_active(): void
    {
        $this->actAs('agent');

        CannedResponse::create(['title' => 'EN one', 'body' => 'b', 'language' => 'en']);
        CannedResponse::create(['title' => 'Any', 'body' => 'b', 'language' => null]);
        CannedResponse::create(['title' => 'BM one', 'body' => 'b', 'language' => 'ms']);
        CannedResponse::create(['title' => 'Off', 'body' => 'b', 'language' => 'en', 'is_active' => false]);

        // EN request → the EN one + the any-language one, never BM or inactive.
        $titles = collect($this->getJson('/api/v1/agent/canned-responses?language=en')->assertOk()->json('data'))
            ->pluck('title')->sort()->values()->all();

        $this->assertSame(['Any', 'EN one'], $titles);
    }
}
