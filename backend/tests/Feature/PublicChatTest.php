<?php

namespace Tests\Feature;

use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Inbox;
use App\Models\RoutingRule;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicChatTest extends TestCase
{
    use RefreshDatabase;

    private function startEndpoint(Inbox $inbox): string
    {
        return "/api/v1/public/inboxes/{$inbox->slug}/conversations";
    }

    public function test_start_creates_conversation_and_bot_answers_on_faq_hit(): void
    {
        $inbox = Inbox::factory()->create();
        $faq = Faq::factory()->create([
            'keywords' => ['kata laluan', 'lupa'],
            'answer' => 'Klik "Lupa kata laluan" pada halaman log masuk.',
        ]);

        $response = $this->postJson($this->startEndpoint($inbox), [
            'message' => 'Saya lupa kata laluan saya, boleh bantu?',
            'visitor_name' => 'Ahmad',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'bot')
            ->assertJsonPath('data.visitor.name', 'Ahmad')
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.sender_type', 'bot')
            ->assertJsonPath('data.messages.1.body', $faq->answer)
            ->assertJsonPath('data.messages.1.meta.faq_id', $faq->id);

        $this->assertSame(1, $faq->fresh()->usage_count);
        $this->assertDatabaseHas('faq_queries', ['matched_faq_id' => $faq->id]);
    }

    public function test_start_escalates_to_queue_when_bot_misses(): void
    {
        $inbox = Inbox::factory()->create();

        $response = $this->postJson($this->startEndpoint($inbox), [
            'message' => 'Sesuatu yang tiada jawapan langsung.',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'awaiting_acceptance');

        $this->assertDatabaseHas('faq_queries', ['matched_faq_id' => null]);
    }

    public function test_start_skips_bot_when_disabled(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        Faq::factory()->create(['keywords' => ['kata laluan']]);

        $response = $this->postJson($this->startEndpoint($inbox), [
            'message' => 'kata laluan',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'awaiting_acceptance');
        $this->assertDatabaseCount('faq_queries', 0);
    }

    public function test_start_applies_routing_rules_on_escalation(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        $team = Team::factory()->create();
        RoutingRule::factory()->for($inbox)->create([
            'conditions' => ['language' => 'ms'],
            'team_id' => $team->id,
        ]);

        $response = $this->postJson($this->startEndpoint($inbox), [
            'message' => 'Perlukan bantuan manusia.',
            'language' => 'ms',
        ]);

        $response->assertCreated()->assertJsonPath('data.team.id', $team->id);
    }

    public function test_english_fallback_answers_when_no_bm_faq_matches(): void
    {
        $inbox = Inbox::factory()->create();
        $english = Faq::factory()->english()->create([
            'keywords' => ['pricing'],
            'answer' => 'See the pricing page.',
        ]);

        $response = $this->postJson($this->startEndpoint($inbox), [
            'message' => 'pricing please',
            'language' => 'ms',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'bot')
            ->assertJsonPath('data.messages.1.body', $english->answer);
    }

    public function test_visitor_message_on_active_conversation_gets_no_bot_reply(): void
    {
        $conversation = Conversation::factory()->active()->create();

        $response = $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/messages", [
            'body' => 'Are you still there?',
        ]);

        $response->assertCreated()
            ->assertJsonPath('bot_reply', null)
            ->assertJsonPath('status', 'active');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'body' => 'Are you still there?',
        ]);
    }

    public function test_visitor_message_rejected_when_closed(): void
    {
        $conversation = Conversation::factory()->closed()->create();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/messages", [
            'body' => 'Hello?',
        ])->assertStatus(409);
    }

    public function test_escalate_endpoint_moves_bot_conversation_to_queue(): void
    {
        $conversation = Conversation::factory()->create(['status' => ConversationStatus::Bot]);

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/escalate")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_acceptance');
    }

    public function test_close_endpoint_closes_and_appends_system_message(): void
    {
        $conversation = Conversation::factory()->active()->create();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_type' => 'system',
        ]);
    }

    public function test_show_returns_conversation_with_messages(): void
    {
        $conversation = Conversation::factory()->create();
        $conversation->messages()->create(['sender_type' => 'visitor', 'body' => 'Hi']);

        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $conversation->uuid)
            ->assertJsonCount(1, 'data.messages');
    }

    public function test_unsupported_language_is_rejected(): void
    {
        $inbox = Inbox::factory()->create();

        $this->postJson($this->startEndpoint($inbox), [
            'message' => 'Bonjour',
            'language' => 'fr',
        ])->assertUnprocessable();
    }

    public function test_inactive_inbox_is_not_found(): void
    {
        $inbox = Inbox::factory()->create(['is_active' => false]);

        $this->postJson($this->startEndpoint($inbox), ['message' => 'Hi'])->assertNotFound();
    }

    public function test_mitel_inbox_is_not_available_in_phase_2(): void
    {
        $inbox = Inbox::factory()->mitelOpenMedia()->create();

        $this->assertSame(ChannelType::MitelOpenMedia, $inbox->channel_type);
        $this->postJson($this->startEndpoint($inbox), ['message' => 'Hi'])->assertStatus(501);
    }
}
