<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TranscriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_the_public_transcript_without_internal_notes(): void
    {
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()->active()->create([
            'visitor_name' => 'Ali bin Abu',
            'assigned_agent_id' => $agent->id,
        ]);
        $conversation->messages()->create(['sender_type' => 'visitor', 'body' => 'I need help with my visa']);
        $conversation->messages()->create(['sender_type' => 'agent', 'agent_id' => $agent->id, 'body' => 'Happy to help']);
        $conversation->messages()->create(['sender_type' => 'note', 'agent_id' => $agent->id, 'body' => 'SECRET internal note']);

        $response = $this->get("/api/v1/public/conversations/{$conversation->uuid}/transcript");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/html; charset=UTF-8');
        $response->assertSee('Ali bin Abu');
        $response->assertSee('I need help with my visa');
        $response->assertSee('Happy to help');
        // Internal notes must never appear in the public transcript.
        $response->assertDontSee('SECRET internal note');
    }

    public function test_an_unknown_conversation_is_404(): void
    {
        $this->get('/api/v1/public/conversations/'.Str::uuid().'/transcript')->assertNotFound();
    }

    public function test_the_transcript_is_public_no_auth_required(): void
    {
        $conversation = Conversation::factory()->active()->create();
        // No Sanctum::actingAs — must still render.
        $this->get("/api/v1/public/conversations/{$conversation->uuid}/transcript")->assertOk();
    }
}
