<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use App\Services\ConversationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The agent-facing handling audit trail (conversation_events): who accepted,
 * transferred, abandoned and closed — never exposed on the public widget API.
 */
class ConversationActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function service(): ConversationService
    {
        return app(ConversationService::class);
    }

    private function agent(): Agent
    {
        return Agent::factory()->for(User::factory()->for(Role::where('name', 'agent')->firstOrFail()))->create();
    }

    public function test_accept_then_close_are_both_recorded(): void
    {
        $agent = $this->agent();
        $conversation = Conversation::factory()->awaitingAcceptance()->create();

        $this->service()->accept($conversation, $agent);
        $this->service()->close($conversation->refresh(), 'agent');

        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversation->id,
            'type' => 'accepted',
            'actor_name' => $agent->displayName(),
        ]);
        $this->assertDatabaseHas('conversation_events', [
            'conversation_id' => $conversation->id,
            'type' => 'closed',
            'actor_type' => 'agent',
        ]);
    }

    public function test_visitor_abandonment_logs_the_reason_and_who_was_handling_it(): void
    {
        $agent = $this->agent();
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);

        $this->service()->abandonAfterLeaving($conversation);

        $event = $conversation->events()->where('type', 'abandoned')->firstOrFail();
        $this->assertSame('visitor', $event->actor_type);
        $this->assertSame('visitor_left', $event->detail['reason']);
        $this->assertSame($agent->displayName(), $event->detail['was_with']);
        // Attribution is preserved — the agent is never cleared on abandon.
        $this->assertSame($agent->id, $conversation->refresh()->assigned_agent_id);
    }

    public function test_activity_is_on_the_agent_resource_but_never_the_public_one(): void
    {
        $agent = $this->agent();
        $conversation = Conversation::factory()->active()->create(['assigned_agent_id' => $agent->id]);
        $conversation->recordEvent('accepted', 'agent', $agent->displayName());

        Sanctum::actingAs($agent->user);
        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.activity.0.type', 'accepted')
            ->assertJsonPath('data.activity.0.actor_name', $agent->displayName());

        // The public widget endpoint must never leak the internal audit trail.
        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonMissingPath('data.activity');
    }
}
