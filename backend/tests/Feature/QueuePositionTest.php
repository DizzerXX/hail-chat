<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The widget shows a queued visitor their place in line. Standalone counts the
 * conversations ahead in the same team/inbox queue; Mitel uses MiCC's position.
 */
class QueuePositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_queued_conversations_ahead_in_the_same_inbox(): void
    {
        $inbox = Inbox::factory()->create();
        $c1 = Conversation::factory()->awaitingAcceptance()->for($inbox)->create();
        $c2 = Conversation::factory()->awaitingAcceptance()->for($inbox)->create();
        $c3 = Conversation::factory()->awaitingAcceptance()->for($inbox)->create();

        $this->getJson("/api/v1/public/conversations/{$c1->uuid}")->assertJsonPath('data.queue_position', 1);
        $this->getJson("/api/v1/public/conversations/{$c2->uuid}")->assertJsonPath('data.queue_position', 2);
        $this->getJson("/api/v1/public/conversations/{$c3->uuid}")->assertJsonPath('data.queue_position', 3);
    }

    public function test_position_is_scoped_to_the_team_when_routed(): void
    {
        $inbox = Inbox::factory()->create();
        $team = Team::factory()->create();
        Conversation::factory()->awaitingAcceptance()->for($inbox)->create(['team_id' => $team->id]);
        $second = Conversation::factory()->awaitingAcceptance()->for($inbox)->create(['team_id' => $team->id]);
        $unrouted = Conversation::factory()->awaitingAcceptance()->for($inbox)->create(['team_id' => null]);

        // Second in the team queue; the unrouted chat is #1 in its own queue.
        $this->getJson("/api/v1/public/conversations/{$second->uuid}")->assertJsonPath('data.queue_position', 2);
        $this->getJson("/api/v1/public/conversations/{$unrouted->uuid}")->assertJsonPath('data.queue_position', 1);
    }

    public function test_mitel_position_comes_from_micc_meta(): void
    {
        $inbox = Inbox::factory()->mitelOpenMedia()->create();
        $conversation = Conversation::factory()->awaitingAcceptance()->for($inbox)->create([
            'meta' => ['mitel' => ['position_in_queue' => 4]],
        ]);

        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertJsonPath('data.queue_position', 4);
    }

    public function test_a_non_queued_conversation_has_no_position(): void
    {
        $conversation = Conversation::factory()->active()->create();

        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonMissingPath('data.queue_position');
    }
}
