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

    private function enable(string $method, string $endpoint = 'https://ai.test/translate'): void
    {
        config([
            'translation.enabled' => true,
            'translation.method' => $method,
            'translation.endpoint' => $endpoint,
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
        Http::fake(['ai.test/*' => Http::response(['translation' => 'I need help'])]);

        $conversation = $this->conversationWithVisitorMessage();
        $message = $conversation->messages()->first();
        $url = "/api/v1/agent/conversations/{$conversation->uuid}/messages/{$message->id}/translate";

        $this->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.meta.translations.en', 'I need help');

        Http::assertSent(function ($request) {
            return $request['text'] === 'Saya perlukan bantuan'
                && $request['source'] === 'ms'
                && $request['target'] === 'en';
        });
        Event::assertDispatched(MessageTranslated::class, function (MessageTranslated $event) {
            return $event->broadcastAs() === 'message.translated'
                && ($event->broadcastWith()['translations']['en'] ?? null) === 'I need help';
        });

        // A second request reuses the cached translation — no new AI call.
        $this->postJson($url)->assertOk();
        Http::assertSentCount(1);
    }

    public function test_draft_translation_returns_without_storing(): void
    {
        $this->enable('manual');
        Http::fake(['ai.test/*' => Http::response(['translation' => 'Selamat pagi'])]);

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
            'ai.test/*' => Http::response(['translation' => '[t]']),
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
