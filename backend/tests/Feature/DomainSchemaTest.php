<?php

namespace Tests\Feature;

use App\Enums\AgentStatus;
use App\Enums\ChannelType;
use App\Enums\ConversationStatus;
use App\Enums\SenderType;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Faq;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\RoutingRule;
use App\Models\Team;
use App\Models\Webhook;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_conversation_graph_can_be_created(): void
    {
        $inbox = Inbox::factory()->create();
        $team = Team::factory()->create();
        $agent = Agent::factory()->available()->create();
        $agent->teams()->attach($team);

        $conversation = Conversation::factory()
            ->for($inbox)
            ->create([
                'status' => ConversationStatus::Active,
                'assigned_agent_id' => $agent->id,
                'team_id' => $team->id,
                'meta' => ['page' => '/pricing', 'referrer' => 'google'],
            ]);

        Message::factory()->count(2)->for($conversation)->create();
        Message::factory()->for($conversation)->create([
            'sender_type' => SenderType::Agent,
            'agent_id' => $agent->id,
        ]);

        $conversation->refresh();

        $this->assertNotNull($conversation->uuid);
        $this->assertSame(ConversationStatus::Active, $conversation->status);
        $this->assertSame(ChannelType::Standalone, $conversation->inbox->channel_type);
        $this->assertSame(AgentStatus::Available, $conversation->assignedAgent->status);
        $this->assertSame(['page' => '/pricing', 'referrer' => 'google'], $conversation->meta);
        $this->assertCount(3, $conversation->messages);
        $this->assertTrue($agent->teams->contains($team));
        $this->assertTrue($inbox->conversations->contains($conversation));
        $this->assertSame($agent->user->id, $agent->user_id);
    }

    public function test_conversation_uuid_is_generated_and_unique_index_allows_null_work_items(): void
    {
        $conversations = Conversation::factory()->count(2)->create();

        $this->assertNotNull($conversations[0]->uuid);
        $this->assertNotSame($conversations[0]->uuid, $conversations[1]->uuid);
        $this->assertNull($conversations[0]->external_work_item_id);
        $this->assertDatabaseCount('conversations', 2);
    }

    public function test_faq_language_scope_and_translation_grouping(): void
    {
        $ms = Faq::factory()->create(['question' => 'Bagaimana saya menetapkan semula kata laluan?']);
        $en = Faq::factory()->english()->inGroup($ms->translation_group)->create([
            'question' => 'How do I reset my password?',
        ]);
        Faq::factory()->create();

        $this->assertCount(2, Faq::language('ms')->get());
        $this->assertCount(1, Faq::language('en')->get());
        $this->assertTrue($ms->translations()->get()->contains($en));
    }

    public function test_faq_translation_group_is_unique_per_language(): void
    {
        $ms = Faq::factory()->create();

        $this->expectException(QueryException::class);

        Faq::factory()->inGroup($ms->translation_group)->create();
    }

    public function test_routing_rule_rejects_zero_targets(): void
    {
        $this->expectException(QueryException::class);

        RoutingRule::factory()->create(['team_id' => null, 'agent_id' => null]);
    }

    public function test_routing_rule_rejects_two_targets(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->create();

        $this->expectException(QueryException::class);

        RoutingRule::factory()->create(['team_id' => $team->id, 'agent_id' => $agent->id]);
    }

    public function test_routing_rule_accepts_single_target(): void
    {
        $rule = RoutingRule::factory()->create();
        $agentRule = RoutingRule::factory()->toAgent()->create();

        $this->assertNotNull($rule->team);
        $this->assertNull($rule->agent);
        $this->assertNotNull($agentRule->agent);
        $this->assertNull($agentRule->team);
    }

    public function test_webhook_secret_is_generated_when_not_provided(): void
    {
        $webhook = new Webhook([
            'name' => 'CRM sync',
            'url' => 'https://example.test/hooks',
            'events' => ['conversation.started'],
        ]);
        $webhook->save();

        $this->assertNotEmpty($webhook->secret);
        $this->assertTrue($webhook->subscribesTo(\App\Enums\WebhookEvent::ConversationStarted));
        $this->assertFalse($webhook->subscribesTo(\App\Enums\WebhookEvent::FaqMissed));
    }
}
