<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Jobs\PurgeExpiredConversationsJob;
use App\Models\Conversation;
use App\Models\SystemSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetentionPurgeTest extends TestCase
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

    private function sweep(): void
    {
        (new PurgeExpiredConversationsJob())->handle();
    }

    public function test_it_purges_old_closed_conversations_and_keeps_recent_and_open_ones(): void
    {
        $this->enableRetention(30);

        $oldClosed = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(40),
        ]);
        $recentClosed = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(5),
        ]);
        $oldAbandoned = Conversation::factory()->create([
            'status' => ConversationStatus::Abandoned, 'closed_at' => now()->subDays(60),
        ]);
        $active = Conversation::factory()->create([
            'status' => ConversationStatus::Active, 'closed_at' => null,
        ]);

        $this->sweep();

        $this->assertDatabaseMissing('conversations', ['id' => $oldClosed->id]);
        $this->assertDatabaseMissing('conversations', ['id' => $oldAbandoned->id]);
        $this->assertDatabaseHas('conversations', ['id' => $recentClosed->id]);
        $this->assertDatabaseHas('conversations', ['id' => $active->id]);

        $this->assertSame(2, SystemSettings::current()->fresh()->last_purge_count);
    }

    public function test_it_cascades_messages_when_purging(): void
    {
        $this->enableRetention(30);
        $conversation = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subDays(40),
        ]);
        $conversation->messages()->create(['sender_type' => 'visitor', 'body' => 'Hi']);

        $this->sweep();

        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
    }

    public function test_disabled_retention_purges_nothing(): void
    {
        // enabled defaults to false
        $old = Conversation::factory()->create([
            'status' => ConversationStatus::Closed, 'closed_at' => now()->subYears(2),
        ]);

        $this->sweep();

        $this->assertDatabaseHas('conversations', ['id' => $old->id]);
    }
}
