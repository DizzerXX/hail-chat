<?php

namespace Tests\Feature;

use App\Events\MessageTranslated;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->agent = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'agent')->first()))
            ->create();
        Sanctum::actingAs($this->agent->user);
    }

    private function enable(string $method, string $url = 'https://ai.test'): void
    {
        config([
            'translation.enabled' => true,
            'translation.method' => $method,
            'translation.url' => $url,
        ]);
    }

    private function conversationWithVisitorMessage(): Conversation
    {
        $conversation = Conversation::factory()->active()->create([
            'assigned_agent_id' => $this->agent->id,
            'language' => 'ms',
        ]);
        $conversation->messages()->create(['sender_type' => 'visitor', 'body' => 'Saya perlukan bantuan']);

        return $conversation;
    }

    public function test_endpoints_404_when_translation_is_disabled(): void
    {
        $conversation = $this->conversationWithVisitorMessage();
        $message = $conversation->messages()->first();

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages/{$message->id}/translate")
            ->assertNotFound();
        $this->postJson('/api/v1/agent/translate', ['text' => 'Hello', 'target' => 'ms'])
            ->assertNotFound();
    }

    public function test_enabled_without_endpoint_returns_a_friendly_503(): void
    {
        $this->enable('manual', '');

        $this->postJson('/api/v1/agent/translate', ['text' => 'Hello', 'target' => 'ms'])
            ->assertStatus(503);
    }

    public function test_manual_translation_stores_broadcasts_and_is_idempotent(): void
    {
        $this->enable('manual');
        Event::fake([MessageTranslated::class]);
        Http::fake(['ai.test/*' => Http::response(['success' => true, 'translated_text' => 'I need help'])]);

        $conversation = $this->conversationWithVisitorMessage();
        $message = $conversation->messages()->first();
        $url = "/api/v1/agent/conversations/{$conversation->uuid}/messages/{$message->id}/translate";

        $this->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.meta.translations.en', 'I need help');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://ai.test/translate'
                && $request['text'] === 'Saya perlukan bantuan'
                && $request['source_language'] === 'ms'
                && $request['target_language'] === 'en';
        });
        Event::assertDispatched(MessageTranslated::class, function (MessageTranslated $event) {
            return $event->broadcastAs() === 'message.translated'
                && ($event->broadcastWith()['translations']['en'] ?? null) === 'I need help';
        });

        // A second request reuses the cached translation — no new AI call.
        $this->postJson($url)->assertOk();
        Http::assertSentCount(1);
    }

    /**
     * The agent picks the direction in the chat bubble, so either message can be
     * asked for either language — not just the pair rule's default.
     */
    public function test_an_explicit_target_overrides_the_default_direction(): void
    {
        $this->enable('manual');
        Http::fake(['ai.test/*' => Http::response(['success' => true, 'translated_text' => 'Saya perlukan bantuan'])]);

        // A Malay visitor message asked for in Malay again is a no-op direction,
        // but the agent may still ask for the opposite of the default.
        $conversation = $this->conversationWithVisitorMessage();
        $message = $conversation->messages()->first();

        $this->postJson(
            "/api/v1/agent/conversations/{$conversation->uuid}/messages/{$message->id}/translate",
            ['target' => 'ms'],
        )
            ->assertOk()
            ->assertJsonPath('data.meta.translations.ms', 'Saya perlukan bantuan');

        Http::assertSent(fn ($request) => $request['source_language'] === 'en'
            && $request['target_language'] === 'ms');
    }

    public function test_an_agent_message_can_be_translated_to_english(): void
    {
        $this->enable('manual');
        Http::fake(['ai.test/*' => Http::response(['success' => true, 'translated_text' => 'Good morning'])]);

        $conversation = Conversation::factory()->active()->create([
            'assigned_agent_id' => $this->agent->id,
            'language' => 'ms',
        ]);
        $message = $conversation->messages()->create([
            'sender_type' => 'agent',
            'agent_id' => $this->agent->id,
            'body' => 'Selamat pagi',
        ]);

        // Without a target the pair rule would translate an agent message to the
        // conversation language (ms); the agent asks for en instead.
        $this->postJson(
            "/api/v1/agent/conversations/{$conversation->uuid}/messages/{$message->id}/translate",
            ['target' => 'en'],
        )
            ->assertOk()
            ->assertJsonPath('data.meta.translations.en', 'Good morning');

        Http::assertSent(fn ($request) => $request['target_language'] === 'en');
    }

    public function test_an_unsupported_target_is_rejected(): void
    {
        $this->enable('manual');
        Http::fake();

        $conversation = $this->conversationWithVisitorMessage();
        $message = $conversation->messages()->first();

        $this->postJson(
            "/api/v1/agent/conversations/{$conversation->uuid}/messages/{$message->id}/translate",
            ['target' => 'fr'],
        )->assertUnprocessable();

        Http::assertNothingSent();
    }

    public function test_draft_translation_returns_without_storing(): void
    {
        $this->enable('manual');
        Http::fake(['ai.test/*' => Http::response(['success' => true, 'translated_text' => 'Selamat pagi'])]);

        $this->postJson('/api/v1/agent/translate', ['text' => 'Good morning', 'target' => 'ms'])
            ->assertOk()
            ->assertJsonPath('data.translation', 'Selamat pagi');

        $this->postJson('/api/v1/agent/translate', ['text' => 'Hi', 'target' => 'fr'])
            ->assertUnprocessable();
    }

    public function test_auto_mode_translates_visitor_and_agent_messages_but_not_bot(): void
    {
        $this->enable('auto');
        Http::fake([
            'ai.test/*' => Http::response(['success' => true, 'translated_text' => '[t]']),
        ]);

        // Visitor message through the public API (bot answers via FAQ).
        $inbox = Inbox::factory()->create();
        Faq::factory()->create(['keywords' => ['bantuan'], 'answer' => 'Jawapan']);

        $uuid = $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'saya perlukan bantuan',
            'language' => 'ms',
        ])->assertCreated()->json('data.uuid');

        $conversation = Conversation::where('uuid', $uuid)->sole();
        $visitorMessage = $conversation->messages()->where('sender_type', 'visitor')->sole();
        $botMessage = $conversation->messages()->where('sender_type', 'bot')->sole();

        $this->assertSame('[t]', $visitorMessage->meta['translations']['en'] ?? null);
        $this->assertArrayNotHasKey('translations', $botMessage->meta ?? []);

        // Agent reply translated into the visitor's language.
        $conversation->forceFill(['status' => 'active', 'assigned_agent_id' => $this->agent->id])->save();
        $this->postJson("/api/v1/agent/conversations/{$uuid}/messages", ['body' => 'How can I help?'])
            ->assertCreated();

        $agentMessage = $conversation->messages()->where('sender_type', 'agent')->sole();
        $this->assertSame('[t]', $agentMessage->meta['translations']['ms'] ?? null);

        // Exactly two AI calls: visitor + agent, never the bot.
        Http::assertSentCount(2);
    }

    public function test_ai_failure_never_blocks_the_message(): void
    {
        $this->enable('auto');
        Http::fake(['ai.test/*' => Http::response('boom', 500)]);

        $conversation = $this->conversationWithVisitorMessage();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/messages", [
            'body' => 'masih ada?',
        ])->assertCreated();

        $latest = $conversation->messages()->latest('id')->first();
        $this->assertArrayNotHasKey('translations', $latest->meta ?? []);
    }

    public function test_message_binding_is_scoped_to_the_conversation(): void
    {
        $this->enable('manual');

        $conversation = $this->conversationWithVisitorMessage();
        $other = $this->conversationWithVisitorMessage();
        $foreignMessage = $other->messages()->first();

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/messages/{$foreignMessage->id}/translate")
            ->assertNotFound();
    }

    public function test_app_config_reflects_the_translation_settings(): void
    {
        $this->getJson('/api/v1/agent/app-config')
            ->assertOk()
            ->assertJsonPath('data.translation.enabled', false)
            ->assertJsonPath('data.translation.method', null);

        $this->enable('auto');

        $this->getJson('/api/v1/agent/app-config')
            ->assertOk()
            ->assertJsonPath('data.translation.enabled', true)
            ->assertJsonPath('data.translation.method', 'auto')
            ->assertJsonPath('data.languages', ['ms', 'en']);
    }
}
