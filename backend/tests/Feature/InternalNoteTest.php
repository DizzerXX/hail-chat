<?php

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalNoteTest extends TestCase
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
    }

    private function activeConversation(): Conversation
    {
        return Conversation::factory()->active()->create([
            'assigned_agent_id' => $this->agent->id,
            'language' => 'en',
        ]);
    }

    public function test_an_agent_can_add_a_note(): void
    {
        Sanctum::actingAs($this->agent->user);
        $conversation = $this->activeConversation();

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/notes", [
            'body' => 'Customer sounds frustrated — escalate if it recurs.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'note')
            ->assertJsonPath('data.body', 'Customer sounds frustrated — escalate if it recurs.');
    }

    public function test_a_note_is_hidden_from_the_public_transcript_but_shown_to_agents(): void
    {
        Sanctum::actingAs($this->agent->user);
        $conversation = $this->activeConversation();
        $conversation->messages()->create(['sender_type' => 'visitor', 'body' => 'hi']);

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/notes", ['body' => 'secret note'])
            ->assertCreated();

        // Public (widget) transcript must never contain the note.
        $publicBodies = collect($this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()->json('data.messages'))->pluck('body');
        $this->assertFalse($publicBodies->contains('secret note'));

        // The agent transcript includes it.
        $agentBodies = collect($this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")
            ->assertOk()->json('data.messages'))->pluck('body');
        $this->assertTrue($agentBodies->contains('secret note'));
    }

    public function test_a_note_never_broadcasts_on_the_public_conversation_channel(): void
    {
        $conversation = $this->activeConversation();
        $note = $conversation->messages()->create(['sender_type' => 'note', 'body' => 'n', 'agent_id' => $this->agent->id]);
        $reply = $conversation->messages()->create(['sender_type' => 'agent', 'body' => 'r', 'agent_id' => $this->agent->id]);

        $noteChannels = collect((new MessageSent($note))->broadcastOn())->map(fn (Channel $c) => $c->name);
        $replyChannels = collect((new MessageSent($reply))->broadcastOn())->map(fn (Channel $c) => $c->name);

        // The note goes ONLY to the private per-conversation notes channel.
        $this->assertEqualsCanonicalizing(
            ['private-conversation-notes.'.$conversation->uuid],
            $noteChannels->all(),
        );
        // A normal reply still uses the public conversation channel.
        $this->assertTrue($replyChannels->contains('conversation.'.$conversation->uuid));
    }

    public function test_a_note_does_not_count_as_conversation_activity(): void
    {
        Sanctum::actingAs($this->agent->user);
        $conversation = $this->activeConversation();
        $before = $conversation->last_activity_at;

        $this->travel(5)->minutes();
        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/notes", ['body' => 'n'])
            ->assertCreated();

        $this->assertEquals(
            $before->timestamp,
            $conversation->fresh()->last_activity_at->timestamp,
            'a note must not bump last_activity_at',
        );
    }

    public function test_an_agent_cannot_note_on_another_agents_conversation(): void
    {
        $other = Agent::factory()->for(User::factory()->for(Role::where('name', 'agent')->first()))->create();
        Sanctum::actingAs($other->user);

        $conversation = $this->activeConversation(); // assigned to $this->agent

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/notes", ['body' => 'n'])
            ->assertStatus(403);
    }
}
