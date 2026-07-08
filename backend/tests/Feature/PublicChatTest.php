<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Inbox;
use App\Models\RoutingRule;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    public function test_mitel_inbox_start_creates_a_micc_work_item(): void
    {
        config(['mitel.base_url' => 'https://micc.test/miccsdk']);
        Cache::put('mitel.access_token', 'secret-token');
        Http::fake([
            'micc.test/*' => Http::response(['Id' => 'wi-500', 'ConversationState' => 9, 'QueueName' => 'OpenMediaQ'], 201),
        ]);

        $inbox = Inbox::factory()->mitelOpenMedia()->create([
            'bot_enabled' => false,
            'channel_config' => ['mitel' => ['queue' => 'OpenMediaQ']],
        ]);

        $response = $this->postJson($this->startEndpoint($inbox), [
            'message' => 'Tolong sambungkan saya kepada ejen.',
            'visitor_name' => 'Ahmad',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'awaiting_acceptance');

        $conversation = Conversation::sole();
        $this->assertSame('wi-500', $conversation->external_work_item_id);
        $this->assertSame('Queued', $conversation->external_state);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://micc.test/miccsdk/api/v1/openmedia'
            && $request->data()['Queue'] === 'OpenMediaQ'
            && $request->data()['TargetUriEmbedded'] === true
            && str_contains($request->data()['TargetUri'], $conversation->uuid)
            && $request->header('Authorization')[0] === 'Bearer secret-token');
    }

    public function test_bot_miss_on_mitel_inbox_escalates_into_micc(): void
    {
        config(['mitel.base_url' => 'https://micc.test/miccsdk']);
        Cache::put('mitel.access_token', 'secret-token');
        Http::fake([
            'micc.test/*' => Http::response(['Id' => 'wi-501', 'ConversationState' => 1]),
        ]);

        $inbox = Inbox::factory()->mitelOpenMedia()->create([
            'channel_config' => ['mitel' => ['queue' => 'OpenMediaQ']],
        ]);

        $this->postJson($this->startEndpoint($inbox), ['message' => 'Soalan tanpa jawapan bot.'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_acceptance');

        $this->assertSame('wi-501', Conversation::sole()->external_work_item_id);
        $this->assertSame('Offered', Conversation::sole()->external_state);
    }
}
