<?php

namespace Tests\Feature;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Services\ConversationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QueueEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush(); // the estimate memoises the average handle time
    }

    private function estimate(Conversation $conversation): array
    {
        return app(ConversationService::class)->queueEstimate($conversation->fresh());
    }

    public function test_an_online_agent_yields_a_wait_estimate(): void
    {
        Agent::factory()->create(['status' => AgentStatus::Available]);
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        $result = $this->estimate($conversation);

        $this->assertTrue($result['agents_online']);
        $this->assertSame(1, $result['queue_position']);
        // No closed history yet, so avg handle = the 300s default; position 1 / 1 agent.
        $this->assertSame(300, $result['estimated_wait_seconds']);
    }

    public function test_no_online_agents_triggers_the_notice(): void
    {
        Agent::factory()->create(['status' => AgentStatus::Offline]);
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        $result = $this->estimate($conversation);

        $this->assertFalse($result['agents_online']);
        $this->assertNull($result['estimated_wait_seconds']);
    }

    public function test_all_away_agents_show_position_without_an_estimate(): void
    {
        // Present but not working — someone's around, but no throughput to estimate.
        Agent::factory()->create(['status' => AgentStatus::Away]);
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        $result = $this->estimate($conversation);

        $this->assertTrue($result['agents_online']);
        $this->assertNull($result['estimated_wait_seconds']);
    }

    public function test_mitel_inboxes_get_no_estimate_and_never_the_notice(): void
    {
        $inbox = Inbox::factory()->mitelOpenMedia()->create();
        $conversation = Conversation::factory()->for($inbox)->awaitingAcceptance()->create([
            'meta' => ['mitel' => ['position_in_queue' => 3]],
        ]);

        $result = $this->estimate($conversation);

        $this->assertTrue($result['agents_online']);
        $this->assertNull($result['estimated_wait_seconds']);
        $this->assertSame(3, $result['queue_position']); // MiCC-reported
    }
}
