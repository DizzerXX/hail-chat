<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

    public function test_a_visitor_can_upload_an_image(): void
    {
        $conversation = $this->activeConversation();

        $response = $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertCreated();

        $response->assertJsonPath('message.sender_type', 'visitor');
        $response->assertJsonPath('message.attachments.0.name', 'photo.jpg');
        $response->assertJsonPath('message.attachments.0.is_image', true);
        $this->assertStringContainsString('/api/v1/public/attachments/', $response->json('message.attachments.0.url'));

        // File actually landed on the disk under the conversation folder.
        $attachment = $conversation->messages()->first()->attachments()->first();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith("attachments/{$conversation->uuid}/", $attachment->path);
    }

    public function test_an_agent_can_upload_a_file_with_a_caption(): void
    {
        Sanctum::actingAs($this->agent->user);
        $conversation = $this->activeConversation();

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf'),
            'body' => 'Here is your invoice',
        ])
            ->assertCreated()
            ->assertJsonPath('data.sender_type', 'agent')
            ->assertJsonPath('data.body', 'Here is your invoice')
            ->assertJsonPath('data.attachments.0.name', 'invoice.pdf')
            ->assertJsonPath('data.attachments.0.is_image', false);
    }

    public function test_an_attachment_streams_by_uuid_and_unknown_uuid_is_404(): void
    {
        $conversation = $this->activeConversation();
        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->image('photo.png'),
        ])->assertCreated();

        $attachment = $conversation->messages()->first()->attachments()->first();

        $this->get("/api/v1/public/attachments/{$attachment->uuid}")
            ->assertOk()
            ->assertHeader('content-type', $attachment->mime_type);

        $this->get('/api/v1/public/attachments/'.\Illuminate\Support\Str::uuid())->assertNotFound();
    }

    public function test_the_transcript_carries_attachment_urls(): void
    {
        $conversation = $this->activeConversation();
        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertCreated();

        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.attachments.0.name', 'photo.jpg');
    }

    public function test_oversize_files_are_rejected(): void
    {
        $conversation = $this->activeConversation();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'), // 11 MB > 10 MB
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_disallowed_types_are_rejected(): void
    {
        $conversation = $this->activeConversation();

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_an_agent_cannot_attach_to_another_agents_conversation(): void
    {
        $other = Agent::factory()->for(User::factory()->for(Role::where('name', 'agent')->first()))->create();
        Sanctum::actingAs($other->user);

        $conversation = $this->activeConversation(); // assigned to $this->agent

        $this->postJson("/api/v1/agent/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertStatus(403);
    }

    public function test_cannot_attach_to_a_closed_conversation(): void
    {
        $conversation = Conversation::factory()->create([
            'status' => 'closed',
            'assigned_agent_id' => $this->agent->id,
            'language' => 'en',
        ]);

        $this->postJson("/api/v1/public/conversations/{$conversation->uuid}/attachments", [
            'file' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertStatus(409);
    }
}
