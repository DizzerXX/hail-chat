<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        // Admin holds conversations.view_all, so it can search scope=all.
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->firstOrFail())->create());
    }

    private function search(string $q): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/agent/conversations?scope=all&q='.urlencode($q));
    }

    public function test_matches_the_visitor_name(): void
    {
        $match = Conversation::factory()->active()->create(['visitor_name' => 'Zulkifli Rahman']);
        Conversation::factory()->active()->create(['visitor_name' => 'Aminah Yusof']);

        $this->search('zulkifli')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $match->uuid);
    }

    public function test_matches_a_word_in_the_transcript(): void
    {
        $match = Conversation::factory()->active()->create(['visitor_name' => 'Aminah']);
        Message::factory()->for($match)->create(['body' => 'I would like a refund for my order']);

        $other = Conversation::factory()->active()->create(['visitor_name' => 'Siti']);
        Message::factory()->for($other)->create(['body' => 'What are your opening hours?']);

        $this->search('refund')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $match->uuid);
    }

    public function test_excludes_recycle_bin_conversations(): void
    {
        $trashed = Conversation::factory()->active()->create(['visitor_name' => 'Zulkifli Deleted']);
        $trashed->delete();

        $this->search('zulkifli')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_one_character_term_is_ignored(): void
    {
        Conversation::factory()->active()->create(['visitor_name' => 'Zulkifli']);
        Conversation::factory()->active()->create(['visitor_name' => 'Aminah']);

        // Too short to search — the filter is skipped and all rows return.
        $this->search('z')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_scope_still_gates_who_can_search_everything(): void
    {
        Sanctum::actingAs(
            User::factory()->for(Role::where('name', 'agent')->firstOrFail())->create()
        );

        $this->search('anything')->assertForbidden();
    }
}
