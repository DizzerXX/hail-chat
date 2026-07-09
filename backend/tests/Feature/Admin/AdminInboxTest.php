<?php

namespace Tests\Feature\Admin;

use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->first())->create());
    }

    public function test_crud_lifecycle(): void
    {
        $created = $this->postJson('/api/v1/admin/inboxes', [
            'name' => 'Website',
            'slug' => 'website',
            'channel_type' => 'standalone',
            'bot_enabled' => true,
            'channel_config' => ['bot' => ['escalate_on_miss' => false]],
        ])->assertCreated()->json('data');

        $this->assertSame('website', $created['slug']);
        $this->assertFalse($created['channel_config']['bot']['escalate_on_miss']);

        $this->getJson('/api/v1/admin/inboxes')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/admin/inboxes/{$created['id']}", [
            'name' => 'Website MY',
            'slug' => 'website',
            'channel_type' => 'mitel_openmedia',
            'channel_config' => ['mitel' => ['queue' => 'OpenMediaQ']],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Website MY')
            ->assertJsonPath('data.channel_type', 'mitel_openmedia')
            ->assertJsonPath('data.channel_config.mitel.queue', 'OpenMediaQ');

        $this->deleteJson("/api/v1/admin/inboxes/{$created['id']}")->assertNoContent();
        $this->assertDatabaseCount('inboxes', 0);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Inbox::factory()->create(['slug' => 'website']);

        $this->postJson('/api/v1/admin/inboxes', [
            'name' => 'Duplicate slug inbox',
            'slug' => 'website',
            'channel_type' => 'standalone',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_inbox_with_conversations_cannot_be_deleted(): void
    {
        $inbox = Inbox::factory()->create();
        Conversation::factory()->for($inbox)->create();

        $this->deleteJson("/api/v1/admin/inboxes/{$inbox->id}")->assertStatus(409);
        $this->assertDatabaseHas('inboxes', ['id' => $inbox->id]);
    }

    public function test_mitel_queue_listing_requires_configuration(): void
    {
        $this->getJson('/api/v1/admin/mitel/queues')->assertStatus(503);
    }
}
