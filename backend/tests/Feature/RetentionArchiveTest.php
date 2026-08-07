<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Jobs\ArchiveExpiredConversationsJob;
use App\Models\Conversation;
use App\Models\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention now *archives* expired conversations into the recycle bin
 * (soft-delete) rather than deleting them outright; the recycle-bin purge does
 * the permanent removal later. See RecycleBinTest.
 */
class RetentionArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSettings::forgetCurrent();
    }

    private function enableRetention(int $days = 30): void
    {
        SystemSettings::current()->update(['retention_enabled' => true, 'retention_days' => $days]);
        SystemSettings::forgetCurrent();
    }

    private function archive(): void
    {
        (new ArchiveExpiredConversationsJob())->handle();
    }

    public function test_it_archives_old_closed_conversations_and_leaves_recent_and_open_ones(): void
    {
        $this->enableRetention(30);

        $oldClosed = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(40),
        ]);
        $recentClosed = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(5),
        ]);
        $active = Conversation::factory()->create([
            'status' => ConversationStatus::Active, 'closed_at' => null,
        ]);

        $this->archive();

        // Old one is in the bin (soft-deleted), tagged as a system archive.
        $this->assertSoftDeleted('conversations', ['id' => $oldClosed->id]);
        $this->assertNull(Conversation::withTrashed()->find($oldClosed->id)->deleted_by);

        // Recent + active are untouched.
        $this->assertNotSoftDeleted('conversations', ['id' => $recentClosed->id]);
        $this->assertNotSoftDeleted('conversations', ['id' => $active->id]);
    }

    public function test_archiving_keeps_the_messages_until_the_bin_is_purged(): void
    {
        $this->enableRetention(30);
        $conversation = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(40),
        ]);
        $conversation->messages()->create(['sender_type' => 'visitor', 'body' => 'Hi']);

        $this->archive();

        // Soft delete doesn't cascade — the transcript is still recoverable.
        $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id]);
    }

    public function test_disabled_retention_archives_nothing(): void
    {
        $old = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subYears(2),
        ]);

        $this->archive();

        $this->assertNotSoftDeleted('conversations', ['id' => $old->id]);
    }
}
