<?php

namespace Tests\Feature;

use App\Models\ChatSettings;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Services\WorkItems\MitelOpenMediaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A cascade node may name the MiCC queue its branch belongs to, so the
 * visitor's own choice decides where the work item lands. The inbox's queue
 * remains the default for paths that name none.
 */
class CascadeQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    /** A tree with a queue on a mid-level node and a deeper override under it. */
    private const TREE = [
        [
            'key' => 'passport',
            'label' => 'Passport',
            'queue' => 'PassportQ',
            'children' => [
                ['key' => 'renew', 'label' => 'Renew'],
                ['key' => 'lost', 'label' => 'Lost', 'queue' => 'UrgentQ'],
            ],
        ],
        [
            'key' => 'visa',
            'label' => 'Visa',
            'children' => [
                ['key' => 'student', 'label' => 'Student'],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mitel.base_url' => 'https://micc.test/miccsdk',
            'mitel.client_id' => 'tdechat',
            'mitel.client_secret' => 'someservicesecret',
        ]);
        Cache::put('mitel.access_token', 'secret-token');

        ChatSettings::current()->forceFill(['custom_fields' => [
            ['key' => 'service', 'label' => 'Service', 'type' => 'cascade', 'required' => true, 'tree' => self::TREE],
        ]])->save();
    }

    private function startWith(array $path): Conversation
    {
        $inbox = Inbox::factory()->mitelOpenMedia()->create([
            'channel_config' => ['mitel' => ['queue' => 'DefaultQ']],
        ]);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Hi',
            'fields' => ['service' => $path],
        ])->assertCreated();

        return $inbox->conversations()->sole();
    }

    /** The create call is POST /api/v1/openmedia; assert its Queue, not any call. */
    private function assertQueueSentWas(string $expected): void
    {
        $queues = collect(Http::recorded())
            ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/api/v1/openmedia'))
            ->map(fn (array $pair) => $pair[0]->data()['Queue'] ?? null);

        $this->assertCount(1, $queues, 'Expected exactly one create-work-item call.');
        $this->assertSame($expected, $queues->first());
    }

    public function test_a_branch_queue_is_used_when_the_leaf_names_none(): void
    {
        Http::fake(['micc.test/*' => Http::response(['Id' => 'wi-1', 'ConversationState' => 9], 201)]);

        $conversation = $this->startWith(['passport', 'renew']);

        $this->assertSame('PassportQ', $conversation->meta['routed_queue']);
        $this->assertQueueSentWas('PassportQ');
    }

    public function test_the_deepest_queue_wins(): void
    {
        Http::fake(['micc.test/*' => Http::response(['Id' => 'wi-2', 'ConversationState' => 9], 201)]);

        $conversation = $this->startWith(['passport', 'lost']);

        $this->assertSame('UrgentQ', $conversation->meta['routed_queue']);
        $this->assertQueueSentWas('UrgentQ');
    }

    public function test_a_path_naming_no_queue_falls_back_to_the_inbox(): void
    {
        Http::fake(['micc.test/*' => Http::response(['Id' => 'wi-3', 'ConversationState' => 9], 201)]);

        $conversation = $this->startWith(['visa', 'student']);

        $this->assertArrayNotHasKey('routed_queue', $conversation->meta ?? []);
        $this->assertQueueSentWas('DefaultQ');
    }

    public function test_the_chosen_queue_is_sent_to_micc_on_create(): void
    {
        Http::fake(['micc.test/*' => Http::response(['Id' => 'wi-4', 'ConversationState' => 9], 201)]);

        $inbox = Inbox::factory()->mitelOpenMedia()->create([
            'channel_config' => ['mitel' => ['queue' => 'DefaultQ']],
        ]);
        $conversation = Conversation::factory()->for($inbox)->create([
            'meta' => ['routed_queue' => 'UrgentQ'],
        ]);

        app(MitelOpenMediaProvider::class)->createWorkItem($conversation);

        $this->assertQueueSentWas('UrgentQ');
    }
}
