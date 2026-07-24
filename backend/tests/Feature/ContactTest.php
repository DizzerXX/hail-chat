<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function start(Inbox $inbox, array $visitor): Conversation
    {
        $uuid = $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", array_merge([
            'message' => 'hello',
            'language' => 'en',
        ], $visitor))->assertCreated()->json('data.uuid');

        return Conversation::where('uuid', $uuid)->sole();
    }

    public function test_conversations_with_the_same_email_share_one_contact(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);

        $first = $this->start($inbox, ['visitor_name' => 'Aisha', 'visitor_email' => 'aisha@example.com']);
        $second = $this->start($inbox, ['visitor_email' => 'aisha@example.com']);

        $this->assertSame(1, Contact::count());
        $this->assertNotNull($first->contact_id);
        $this->assertSame($first->contact_id, $second->contact_id);
        // Name learned from the first chat is retained.
        $this->assertSame('Aisha', Contact::first()->name);
    }

    public function test_contacts_also_match_by_phone(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);

        $a = $this->start($inbox, ['visitor_phone' => '+60123456789']);
        $b = $this->start($inbox, ['visitor_phone' => '+60123456789']);

        $this->assertSame(1, Contact::count());
        $this->assertSame($a->contact_id, $b->contact_id);
    }

    public function test_no_identifier_means_no_contact(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);

        $conversation = $this->start($inbox, ['visitor_name' => 'Anon']);

        $this->assertNull($conversation->contact_id);
        $this->assertSame(0, Contact::count());
    }

    public function test_history_returns_prior_conversations_for_the_contact(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        $first = $this->start($inbox, ['visitor_email' => 'sam@example.com']);
        $current = $this->start($inbox, ['visitor_email' => 'sam@example.com']);

        $agent = Agent::factory()->for(User::factory()->for(Role::where('name', 'supervisor')->first()))->create();
        Sanctum::actingAs($agent->user);

        $response = $this->getJson("/api/v1/agent/conversations/{$current->uuid}/history")->assertOk();

        $response->assertJsonPath('data.contact.email', 'sam@example.com');
        $response->assertJsonCount(1, 'data.conversations');
        $response->assertJsonPath('data.conversations.0.uuid', $first->uuid);
    }

    public function test_history_is_empty_without_a_contact(): void
    {
        $inbox = Inbox::factory()->create(['bot_enabled' => false]);
        $conversation = $this->start($inbox, ['visitor_name' => 'Anon']);

        $agent = Agent::factory()->for(User::factory()->for(Role::where('name', 'supervisor')->first()))->create();
        Sanctum::actingAs($agent->user);

        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}/history")
            ->assertOk()
            ->assertJsonPath('data.contact', null)
            ->assertJsonCount(0, 'data.conversations');
    }
}
