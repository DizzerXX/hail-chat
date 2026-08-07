<?php

namespace Tests\Feature;

use App\Jobs\PurgeRecycleBinJob;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin recycle bin: delete → bin, restore, permanent purge, and the daily
 * auto-purge after recycle_bin_days.
 */
class RecycleBinTest extends TestCase
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

    /** Backdate a soft-deleted conversation's deletion time. */
    private function deletedDaysAgo(int $days): Conversation
    {
        $conversation = Conversation::factory()->create();
        $conversation->delete();
        Conversation::withTrashed()->whereKey($conversation->id)->update(['deleted_at' => now()->subDays($days)]);

        return $conversation;
    }

    public function test_admin_can_delete_a_conversation_into_the_bin(): void
    {
        $admin = $this->admin();
        $conversation = Conversation::factory()->create();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/conversations/{$conversation->uuid}")->assertNoContent();

        $this->assertSoftDeleted('conversations', ['id' => $conversation->id]);
        $this->assertSame($admin->id, Conversation::withTrashed()->find($conversation->id)->deleted_by);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'conversation.deleted']);
    }

    public function test_deleting_requires_the_permission(): void
    {
        $agent = User::factory()->for(Role::where('name', 'agent')->firstOrFail())->create();
        $conversation = Conversation::factory()->create();

        Sanctum::actingAs($agent);
        $this->deleteJson("/api/v1/admin/conversations/{$conversation->uuid}")->assertStatus(403);
        $this->assertNotSoftDeleted('conversations', ['id' => $conversation->id]);
    }

    public function test_admin_can_restore_from_the_bin(): void
    {
        $admin = $this->admin();
        $conversation = Conversation::factory()->create();
        $conversation->forceFill(['deleted_by' => $admin->id])->save();
        $conversation->delete();

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/admin/conversations/{$conversation->uuid}/restore")->assertNoContent();

        $this->assertNotSoftDeleted('conversations', ['id' => $conversation->id]);
        $this->assertNull(Conversation::find($conversation->id)->deleted_by);
    }

    public function test_trashed_list_labels_who_deleted_it(): void
    {
        $admin = $this->admin();
        $byAdmin = Conversation::factory()->create();
        $byAdmin->forceFill(['deleted_by' => $admin->id])->save();
        $byAdmin->delete();
        $byRetention = Conversation::factory()->create();
        $byRetention->delete(); // deleted_by null = Retention

        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/admin/conversations/trashed')
            ->assertOk()
            ->assertJsonFragment(['deleted_by' => $admin->name])
            ->assertJsonFragment(['deleted_by' => 'Retention']);
    }

    public function test_force_delete_removes_it_permanently(): void
    {
        $admin = $this->admin();
        $conversation = Conversation::factory()->create();
        $conversation->delete();

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/conversations/{$conversation->uuid}/force")->assertNoContent();

        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $admin->id, 'action' => 'conversation.purged']);
    }

    public function test_the_purge_job_permanently_deletes_bin_items_past_the_window(): void
    {
        $old = $this->deletedDaysAgo(40);
        $recent = $this->deletedDaysAgo(5);

        (new PurgeRecycleBinJob())->handle(app(ConversationService::class));

        $this->assertDatabaseMissing('conversations', ['id' => $old->id]);   // gone for good
        $this->assertSoftDeleted('conversations', ['id' => $recent->id]);    // still restorable
    }
}
