<?php

namespace Tests\Feature\Admin;

use App\Models\Agent;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminWebhookAndRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->first())->create());
    }

    public function test_webhook_crud_exposes_generated_secret(): void
    {
        $created = $this->postJson('/api/v1/admin/webhooks', [
            'name' => 'CRM sync',
            'url' => 'https://crm.example.com/hooks',
            'events' => ['conversation.started', 'conversation.closed'],
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($created['secret']);

        $this->putJson("/api/v1/admin/webhooks/{$created['id']}", [
            'name' => 'CRM sync',
            'url' => 'https://crm.example.com/hooks',
            'events' => ['faq.missed'],
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.events', ['faq.missed'])
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.secret', $created['secret']);

        $this->deleteJson("/api/v1/admin/webhooks/{$created['id']}")->assertNoContent();
    }

    public function test_webhook_events_are_validated(): void
    {
        $this->postJson('/api/v1/admin/webhooks', [
            'name' => 'Bad',
            'url' => 'https://example.com',
            'events' => ['not.an.event'],
        ])->assertUnprocessable()->assertJsonValidationErrors('events.0');
    }

    public function test_deliveries_listing_for_debugging(): void
    {
        $webhook = Webhook::factory()->create();
        WebhookDelivery::factory()->count(2)->failed()->for($webhook)->create();
        WebhookDelivery::factory()->success()->for($webhook)->create();

        $response = $this->getJson("/api/v1/admin/webhooks/{$webhook->id}/deliveries")->assertOk();

        $this->assertCount(3, $response->json('data'));
        $this->assertNotNull($response->json('data.0.payload'));

        $this->getJson('/api/v1/admin/webhooks')
            ->assertOk()
            ->assertJsonPath('data.0.deliveries_count', 3)
            ->assertJsonPath('data.0.failed_deliveries_count', 2);
    }

    public function test_routing_rule_crud_with_single_target(): void
    {
        $inbox = Inbox::factory()->create();
        $team = Team::factory()->create();
        $agent = Agent::factory()->create();

        $rule = $this->postJson("/api/v1/admin/inboxes/{$inbox->id}/routing-rules", [
            'name' => 'BM to General',
            'priority' => 1,
            'conditions' => ['language' => 'ms', 'keywords' => ['bantuan']],
            'team_id' => $team->id,
        ])->assertCreated()->json('data');

        $this->assertSame($team->id, $rule['team']['id']);

        // Both targets → validation error.
        $this->postJson("/api/v1/admin/inboxes/{$inbox->id}/routing-rules", [
            'name' => 'Bad',
            'team_id' => $team->id,
            'agent_id' => $agent->id,
        ])->assertUnprocessable();

        // Switching target type clears the old one.
        $this->putJson("/api/v1/admin/routing-rules/{$rule['id']}", [
            'name' => 'To agent',
            'agent_id' => $agent->id,
        ])->assertOk()
            ->assertJsonPath('data.agent.id', $agent->id)
            ->assertJsonPath('data.team', null);

        $this->getJson("/api/v1/admin/inboxes/{$inbox->id}/routing-rules")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/admin/routing-rules/{$rule['id']}")->assertNoContent();
    }

    public function test_routing_rule_language_condition_is_validated(): void
    {
        $inbox = Inbox::factory()->create();
        $team = Team::factory()->create();

        $this->postJson("/api/v1/admin/inboxes/{$inbox->id}/routing-rules", [
            'name' => 'Bad language',
            'conditions' => ['language' => 'fr'],
            'team_id' => $team->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('conditions.language');
    }
}
