<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Events\AgentNotified;
use App\Jobs\IdleSweepJob;
use App\Models\Agent;
use App\Models\ChatSettings;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IdleSweepTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The settings singleton is process-cached; reset it so each test's
        // RefreshDatabase rollback doesn't leak a stale instance.
        ChatSettings::forgetCurrent();
    }

    private function conversation(ChannelType $type = ChannelType::Standalone): Conversation
    {
        $inbox = Inbox::factory()->create(['channel_type' => $type]);
        $agent = Agent::factory()->create();

        return Conversation::factory()->for($inbox)->create([
            'status' => ConversationStatus::Active,
            'assigned_agent_id' => $agent->id,
            'language' => 'en',
            'visitor_name' => 'Danish',
        ]);
    }

    private function message(Conversation $conversation, SenderType $sender, int $minutesAgo): void
    {
        Message::factory()->for($conversation)->create([
            'sender_type' => $sender,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function sweep(): void
    {
        (new IdleSweepJob())->handle(app(\App\Services\ConversationService::class));
    }

    public function test_customer_idle_warns_at_five_and_eight_then_abandons_at_ten(): void
    {
        Event::fake([AgentNotified::class]);
        $conversation = $this->conversation();
        // Agent spoke last, 6 minutes ago → customer's turn, 6 min idle.
        $this->message($conversation, SenderType::Visitor, 8);
        $this->message($conversation, SenderType::Agent, 6);

        $this->sweep();

        // First warning sent to the visitor + agent notified with the warning sound.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'bot',
            'body' => ChatSettings::current()->text('warn1', 'en'),
        ]);
        Event::assertDispatched(AgentNotified::class, fn ($e) => $e->type === 'customer_idle' && $e->sound === 'warning');

        // Running again at the same time must NOT resend warn1.
        $this->sweep();
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)
            ->where('sender_type', 'bot')->count());
    }

    public function test_customer_abandoned_after_close_threshold(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, SenderType::Visitor, 15);
        $this->message($conversation, SenderType::Agent, 11); // 11 min idle > 10

        $this->sweep();

        $this->assertSame(ConversationStatus::Abandoned, $conversation->fresh()->status);
        $this->assertNotNull($conversation->fresh()->closed_at);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'body' => ChatSettings::current()->text('closed', 'en'),
        ]);
    }

    public function test_agent_idle_nudge_repeats_each_interval(): void
    {
        Event::fake([AgentNotified::class]);
        $conversation = $this->conversation(); // agent_idle default 2 min
        // Visitor spoke last, 5 minutes ago → agent's turn, 5 min idle = 2 intervals.
        $this->message($conversation, SenderType::Agent, 9);
        $this->message($conversation, SenderType::Visitor, 5);

        $this->sweep();
        Event::assertDispatched(AgentNotified::class, fn ($e) => $e->type === 'agent_idle' && $e->sound === 'notification');

        // Same minute, still 2 intervals → no additional nudge.
        $this->sweep();
        Event::assertDispatchedTimes(AgentNotified::class, 1);
    }

    public function test_a_reply_resets_the_idle_block(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, SenderType::Visitor, 8);
        $this->message($conversation, SenderType::Agent, 6);
        $this->sweep();
        $this->assertTrue(($conversation->fresh()->meta['idle']['warn1'] ?? false));

        // Visitor replies now → newest message flips the turn and the anchor.
        $this->message($conversation, SenderType::Visitor, 0);
        $this->sweep();

        $meta = $conversation->fresh()->meta['idle'];
        $this->assertArrayNotHasKey('warn1', $meta, 'a reply must reset the customer-idle flags');
    }

    public function test_mitel_customer_idle_alerts_the_agent_but_never_messages_the_visitor_or_closes(): void
    {
        Event::fake([AgentNotified::class]);
        $conversation = $this->conversation(ChannelType::MitelOpenMedia);
        // Agent spoke last 15 min ago → customer idle, past every threshold.
        $this->message($conversation, SenderType::Visitor, 20);
        $this->message($conversation, SenderType::Agent, 15);

        $this->sweep();

        // The agent is nudged, and offered the "still there?" text as a one-click follow-up
        // (15 min idle → the 2nd-warning text)...
        Event::assertDispatched(AgentNotified::class, fn ($e) => $e->type === 'customer_idle'
            && $e->sound === 'warning'
            && $e->followUp === ChatSettings::current()->text('warn2', 'en'));
        // ...but MiCC owns the case: no visitor warning, and never auto-closed.
        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)
            ->where('sender_type', 'bot')->count());
    }

    public function test_mitel_agent_idle_nudges_the_agent(): void
    {
        Event::fake([AgentNotified::class]);
        $conversation = $this->conversation(ChannelType::MitelOpenMedia);
        // Visitor spoke last → agent's turn.
        $this->message($conversation, SenderType::Agent, 9);
        $this->message($conversation, SenderType::Visitor, 5);

        $this->sweep();

        Event::assertDispatched(AgentNotified::class, fn ($e) => $e->type === 'agent_idle');
        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
    }

    public function test_visitor_who_left_is_abandoned_after_the_grace_window(): void
    {
        $grace = (int) config('chat.visitor_left_grace_seconds');
        $conversation = $this->conversation();
        $conversation->forceFill(['visitor_left_at' => now()->subSeconds($grace + 5)])->saveQuietly();

        $this->sweep();

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Abandoned, $conversation->status);
        $this->assertNotNull($conversation->closed_at);
        $this->assertNull($conversation->visitor_left_at);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
            'body' => 'Visitor left the chat.',
        ]);
    }

    public function test_visitor_who_left_but_returned_within_grace_is_kept(): void
    {
        $conversation = $this->conversation();
        // Left only a moment ago (within grace) → still present as far as we know.
        $conversation->forceFill(['visitor_left_at' => now()->subSeconds(5)])->saveQuietly();

        $this->sweep();

        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
    }

    public function test_mitel_ignores_a_visitor_left_flag(): void
    {
        $grace = (int) config('chat.visitor_left_grace_seconds');
        $conversation = $this->conversation(ChannelType::MitelOpenMedia);
        $conversation->forceFill(['visitor_left_at' => now()->subSeconds($grace + 60)])->saveQuietly();

        $this->sweep();

        // MiCC owns the case lifecycle — never auto-abandoned by our sweep.
        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
    }

    public function test_disabled_idle_does_nothing(): void
    {
        ChatSettings::current()->update(['idle_enabled' => false]);
        ChatSettings::forgetCurrent();

        $conversation = $this->conversation();
        $this->message($conversation, SenderType::Visitor, 20);
        $this->message($conversation, SenderType::Agent, 15);

        $this->sweep();

        $this->assertSame(ConversationStatus::Active, $conversation->fresh()->status);
    }
}
