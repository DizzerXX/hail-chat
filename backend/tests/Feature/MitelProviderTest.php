<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Exceptions\MitelApiException;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Team;
use App\Services\WorkItems\MitelOpenMediaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MitelProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mitel.base_url' => 'https://micc.test/miccsdk',
            'mitel.client_id' => 'tdechat',
            'mitel.client_secret' => 'someservicesecret',
        ]);

        // Pre-seed the OAuth token so these tests exercise the SDK API
        // contract only; the token flow itself is covered by MitelAuthTest.
        Cache::put('mitel.access_token', 'secret-token');
    }

    private function makeMitelConversation(array $conversationAttributes = [], array $mitelConfig = ['queue' => 'OpenMediaQ']): Conversation
    {
        $inbox = Inbox::factory()->mitelOpenMedia()->create([
            'channel_config' => ['mitel' => $mitelConfig],
        ]);

        return Conversation::factory()->for($inbox)->create($conversationAttributes);
    }

    public function test_create_work_item_posts_the_confirmed_contract(): void
    {
        Http::fake([
            'micc.test/*' => Http::response(['Id' => 'wi-1001', 'ConversationState' => 9, 'QueueName' => 'OpenMediaQ', 'PositionInQueue' => 3], 201),
        ]);

        $conversation = $this->makeMitelConversation([
            'visitor_name' => 'Ahmad',
            'visitor_email' => 'ahmad@example.com',
            'visitor_phone' => null,
        ]);
        $conversation->messages()->create([
            'sender_type' => 'visitor',
            'body' => 'Saya perlukan bantuan dengan invois saya yang tertunggak sejak bulan lepas, boleh?',
        ]);

        app(MitelOpenMediaProvider::class)->createWorkItem($conversation);

        $conversation->refresh();
        $this->assertSame('wi-1001', $conversation->external_work_item_id);
        $this->assertSame('Queued', $conversation->external_state);
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->status);
        $this->assertSame('OpenMediaQ', $conversation->meta['mitel']['queue_name']);
        $this->assertSame(3, $conversation->meta['mitel']['position_in_queue']);

        Http::assertSent(function (Request $request) use ($conversation) {
            $data = $request->data();

            return $request->url() === 'https://micc.test/miccsdk/api/v1/openmedia'
                && $request->method() === 'POST'
                && $request->header('Authorization')[0] === 'Bearer secret-token'
                && $data['TargetUriEmbedded'] === true
                && str_contains($data['TargetUri'], $conversation->uuid)
                && $data['Queue'] === 'OpenMediaQ'
                && $data['From'] === 'ahmad@example.com'
                && $data['Subject'] === \Illuminate\Support\Str::limit(
                    'Saya perlukan bantuan dengan invois saya yang tertunggak sejak bulan lepas, boleh?',
                    80,
                )
                && $data['VariableData'] === ['Name' => 'Ahmad', 'Email' => 'ahmad@example.com']
                && ! array_key_exists('Tenant', $data);
        });
    }

    public function test_create_work_item_includes_tenant_when_configured(): void
    {
        config(['mitel.tenant' => 'telediant']);
        Http::fake(['micc.test/*' => Http::response(['Id' => 'wi-1', 'ConversationState' => 1])]);

        app(MitelOpenMediaProvider::class)->createWorkItem($this->makeMitelConversation());

        Http::assertSent(fn (Request $request) => $request->data()['Tenant'] === 'telediant');
    }

    public function test_create_failure_leaves_the_conversation_untouched(): void
    {
        Http::fake(['micc.test/*' => Http::response('server error', 500)]);

        $conversation = $this->makeMitelConversation(['status' => ConversationStatus::Bot]);

        try {
            app(MitelOpenMediaProvider::class)->createWorkItem($conversation);
            $this->fail('Expected MitelApiException.');
        } catch (MitelApiException) {
        }

        $conversation->refresh();
        $this->assertSame(ConversationStatus::Bot, $conversation->status);
        $this->assertNull($conversation->external_work_item_id);
    }

    public function test_create_response_without_id_is_an_error(): void
    {
        Http::fake(['micc.test/*' => Http::response(['ConversationState' => 9])]);

        $this->expectException(MitelApiException::class);

        app(MitelOpenMediaProvider::class)->createWorkItem($this->makeMitelConversation());
    }

    public function test_complete_puts_action_complete_and_closes_locally(): void
    {
        Http::fake(['micc.test/*' => Http::response(['ok' => true])]);

        $conversation = $this->makeMitelConversation([
            'status' => ConversationStatus::Active,
            'external_work_item_id' => 'wi-77',
        ]);

        app(MitelOpenMediaProvider::class)->completeWorkItem($conversation);

        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        $this->assertNotNull($conversation->fresh()->closed_at);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://micc.test/miccsdk/api/v1/openmedia/wi-77'
            && $request->method() === 'PUT'
            && $request->data() === ['Action' => 0]);
    }

    public function test_complete_without_external_id_closes_locally_without_http(): void
    {
        $conversation = $this->makeMitelConversation(['status' => ConversationStatus::Active]);

        app(MitelOpenMediaProvider::class)->completeWorkItem($conversation);

        $this->assertSame(ConversationStatus::Closed, $conversation->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reroute_sends_the_action_and_retargets_locally(): void
    {
        Http::fake(['micc.test/*' => Http::response(['ok' => true])]);

        $team = Team::factory()->create();
        $conversation = $this->makeMitelConversation(
            ['status' => ConversationStatus::Active, 'external_work_item_id' => 'wi-9'],
        );

        app(MitelOpenMediaProvider::class)->rerouteWorkItem($conversation, $team);

        $conversation->refresh();
        $this->assertSame(ConversationStatus::AwaitingAcceptance, $conversation->status);
        $this->assertSame($team->id, $conversation->team_id);
        $this->assertNull($conversation->assigned_agent_id);
        $this->assertNull($conversation->accepted_at);

        // OpenMediaUpdateRequestModel carries only the Action field.
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->data() === ['Action' => 1]);
    }
}
