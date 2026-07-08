<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Jobs\SendWebhookJob;
use App\Models\Inbox;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_started_delivers_signed_payload(): void
    {
        Http::fake(['sink.test/*' => Http::response(['ok' => true])]);

        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        $webhook = Webhook::factory()->create([
            'url' => 'https://sink.test/hooks',
            'events' => [WebhookEvent::ConversationStarted->value],
        ]);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Hello there',
        ])->assertCreated();

        $delivery = WebhookDelivery::sole();
        $this->assertSame(DeliveryStatus::Success, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(200, $delivery->last_status_code);
        $this->assertNotNull($delivery->completed_at);

        Http::assertSent(function (Request $request) use ($webhook, $delivery) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $webhook->secret);
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://sink.test/hooks'
                && $request->header('X-TDEChat-Event')[0] === 'conversation.started'
                && $request->header('X-TDEChat-Delivery')[0] === (string) $delivery->id
                && $request->header('X-TDEChat-Signature')[0] === $expected
                && $payload['event'] === 'conversation.started'
                && isset($payload['data']['conversation']['uuid'], $payload['timestamp']);
        });
    }

    public function test_unsubscribed_events_do_not_deliver(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        Webhook::factory()->create(['events' => [WebhookEvent::ConversationClosed->value]]);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Hello there',
        ])->assertCreated();

        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_inbox_scoping_is_respected(): void
    {
        Http::fake(['*' => Http::response()]);

        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        $otherInbox = Inbox::factory()->create();

        $global = Webhook::factory()->create(['events' => [WebhookEvent::ConversationStarted->value]]);
        $scopedToOther = Webhook::factory()->create([
            'inbox_id' => $otherInbox->id,
            'events' => [WebhookEvent::ConversationStarted->value],
        ]);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Hello there',
        ])->assertCreated();

        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame($global->id, WebhookDelivery::sole()->webhook_id);
    }

    public function test_faq_missed_fires_with_the_query(): void
    {
        Http::fake(['*' => Http::response()]);

        $inbox = Inbox::factory()->create();
        Webhook::factory()->create(['events' => [WebhookEvent::FaqMissed->value]]);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Soalan tanpa jawapan',
        ])->assertCreated();

        $delivery = WebhookDelivery::sole();
        $this->assertSame('faq.missed', $delivery->event);
        $this->assertSame('Soalan tanpa jawapan', $delivery->payload['data']['query']);
        $this->assertNotNull($delivery->payload['data']['conversation']);
    }

    public function test_failing_deliveries_retry_then_reach_terminal_failure(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        Webhook::factory()->create(['events' => [WebhookEvent::ConversationClosed->value]]);

        app(WebhookDispatcher::class)->dispatch(
            WebhookEvent::ConversationClosed,
            ['conversation' => ['uuid' => 'x']],
        );

        $delivery = WebhookDelivery::sole()->fresh();
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(500, $delivery->last_status_code);
        $this->assertSame('boom', $delivery->last_error);
        $this->assertNotNull($delivery->next_attempt_at);

        foreach (range(2, 4) as $attempt) {
            (new SendWebhookJob($delivery))->handle();
            $delivery->refresh();
            $this->assertSame($attempt, $delivery->attempts);
        }

        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertNull($delivery->next_attempt_at);
        $this->assertNotNull($delivery->completed_at);
    }

    public function test_connection_errors_are_recorded_as_failures(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Could not resolve host'));

        Webhook::factory()->create(['events' => [WebhookEvent::ConversationClosed->value]]);

        app(WebhookDispatcher::class)->dispatch(
            WebhookEvent::ConversationClosed,
            ['conversation' => ['uuid' => 'x']],
        );

        $delivery = WebhookDelivery::sole();
        $this->assertSame(DeliveryStatus::Pending, $delivery->status);
        $this->assertNull($delivery->last_status_code);
        $this->assertStringContainsString('Could not resolve host', $delivery->last_error);
    }
}
