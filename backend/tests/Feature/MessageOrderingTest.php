<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Postgres returns rows in heap order unless told otherwise, and an UPDATE
 * rewrites the tuple at the end of the heap. Editing an early message — a
 * translation writing to meta — therefore used to move it to the bottom of the
 * transcript for both the agent and the visitor.
 */
class MessageOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function conversationWithThreeMessages(): Conversation
    {
        $conversation = Conversation::factory()->active()->create(['language' => 'ms']);

        foreach (['salam', 'wksalam', 'terima kasih'] as $body) {
            $conversation->messages()->create(['sender_type' => 'visitor', 'body' => $body]);
        }

        return $conversation;
    }

    private function touchFirstMessage(Conversation $conversation): void
    {
        $first = $conversation->messages()->first();
        $first->meta = ['translations' => ['en' => 'peace']];
        $first->save();
    }

    /**
     * The guard that actually pins the fix. Heap order is not reproducible
     * inside a transactional test, so assert the relation carries the ORDER BY
     * rather than hoping the planner misbehaves on three rows.
     */
    public function test_the_messages_relation_orders_by_id(): void
    {
        $sql = (new Conversation())->messages()->toBase()->toSql();

        $this->assertStringContainsString('order by "id" asc', strtolower($sql));
    }

    public function test_the_agent_transcript_keeps_its_order_after_a_message_is_updated(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $agent = Agent::factory()
            ->for(User::factory()->for(Role::where('name', 'admin')->first()))
            ->create();
        Sanctum::actingAs($agent->user);

        $conversation = $this->conversationWithThreeMessages();
        $this->touchFirstMessage($conversation);

        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'salam')
            ->assertJsonPath('data.messages.1.body', 'wksalam')
            ->assertJsonPath('data.messages.2.body', 'terima kasih');
    }

    public function test_the_widget_transcript_keeps_its_order_too(): void
    {
        $conversation = $this->conversationWithThreeMessages();
        $this->touchFirstMessage($conversation);

        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'salam')
            ->assertJsonPath('data.messages.2.body', 'terima kasih');
    }
}
