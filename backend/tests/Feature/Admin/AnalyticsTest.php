<?php

namespace Tests\Feature\Admin;

use App\Models\Conversation;
use App\Models\Faq;
use App\Models\FaqQuery;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->first())->create());
    }

    public function test_analytics_math_is_exact(): void
    {
        $this->travelTo('2026-07-08 12:00:00');

        // Two conversations on the 7th, one on the 8th.
        Conversation::factory()->count(2)->create(['created_at' => '2026-07-07 10:00:00']);
        Conversation::factory()->create(['created_at' => '2026-07-08 09:00:00']);

        // Handle times: 300s and 600s → avg 450s.
        Conversation::factory()->closed()->create([
            'created_at' => '2026-07-07 10:00:00',
            'accepted_at' => '2026-07-07 10:05:00',
            'closed_at' => '2026-07-07 10:10:00',
        ]);
        Conversation::factory()->closed()->create([
            'created_at' => '2026-07-08 10:00:00',
            'accepted_at' => '2026-07-08 10:00:00',
            'closed_at' => '2026-07-08 10:10:00',
        ]);

        // FAQ lookups: 3 hits out of 5 → 0.6; one distinct missed query twice.
        $faq = Faq::factory()->create();
        FaqQuery::factory()->count(3)->create(['matched_faq_id' => $faq->id]);
        FaqQuery::factory()->count(2)->create(['query_text' => 'macam mana nak refund']);

        $response = $this->getJson('/api/v1/admin/analytics?from=2026-07-07&to=2026-07-08')->assertOk();

        $days = collect($response->json('conversations_per_day'));
        $this->assertCount(2, $days);
        $this->assertSame(3, $days->firstWhere('date', '2026-07-07')['count']);
        $this->assertSame(2, $days->firstWhere('date', '2026-07-08')['count']);

        $this->assertEquals(450, $response->json('avg_handle_seconds'));

        $this->assertSame(5, $response->json('faq.queries'));
        $this->assertSame(3, $response->json('faq.hits'));
        $this->assertSame(0.6, $response->json('faq.hit_rate'));

        $this->assertSame(5, $response->json('totals.conversations'));
        $this->assertSame(2, $response->json('totals.closed'));

        $this->assertSame('macam mana nak refund', $response->json('top_missed_queries.0.query'));
        $this->assertSame(2, $response->json('top_missed_queries.0.count'));
    }

    public function test_per_agent_volume_resolution_and_abandonment_rate(): void
    {
        $this->travelTo('2026-07-08 12:00:00');

        $agent = \App\Models\Agent::factory()->create(['display_name' => 'Aina']);

        // Two conversations Aina closed: 200s and 400s → avg 300s.
        Conversation::factory()->closed()->create([
            'assigned_agent_id' => $agent->id,
            'created_at' => '2026-07-07 10:00:00',
            'accepted_at' => '2026-07-07 10:00:00',
            'closed_at' => '2026-07-07 10:03:20',
        ]);
        Conversation::factory()->closed()->create([
            'assigned_agent_id' => $agent->id,
            'created_at' => '2026-07-07 11:00:00',
            'accepted_at' => '2026-07-07 11:00:00',
            'closed_at' => '2026-07-07 11:06:40',
        ]);

        // Plus one abandoned → 1 of 3 total in range abandoned.
        Conversation::factory()->create([
            'status' => \App\Enums\ConversationStatus::Abandoned,
            'created_at' => '2026-07-07 09:00:00',
        ]);

        $response = $this->getJson('/api/v1/admin/analytics?from=2026-07-07&to=2026-07-08')->assertOk();

        $this->assertSame('Aina', $response->json('per_agent.0.agent'));
        $this->assertSame(2, $response->json('per_agent.0.handled'));
        $this->assertEquals(300, $response->json('per_agent.0.avg_resolution_seconds'));

        $this->assertSame(3, $response->json('totals.conversations'));
        $this->assertSame(1, $response->json('totals.abandoned'));
        $this->assertSame(0.3333, $response->json('totals.abandonment_rate'));
    }

    public function test_range_is_zero_filled_and_defaults_to_14_days(): void
    {
        $response = $this->getJson('/api/v1/admin/analytics')->assertOk();

        $this->assertCount(14, $response->json('conversations_per_day'));
        $this->assertSame(0, $response->json('conversations_per_day.0.count'));
        $this->assertNull($response->json('avg_handle_seconds'));
        $this->assertNull($response->json('faq.hit_rate'));
    }

    public function test_range_validation(): void
    {
        $this->getJson('/api/v1/admin/analytics?from=2026-07-08&to=2026-07-01')
            ->assertUnprocessable();

        $this->getJson('/api/v1/admin/analytics?from=2026-01-01&to=2026-07-01')
            ->assertUnprocessable();
    }
}
