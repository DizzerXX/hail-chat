<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\User;
use App\Services\CmsUrlResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CmsUrlTest extends TestCase
{
    use RefreshDatabase;

    private function resolve(?string $template, Conversation $conversation): ?string
    {
        return app(CmsUrlResolver::class)->resolve($template, $conversation);
    }

    public function test_it_substitutes_placeholders_and_url_encodes_values(): void
    {
        $conversation = Conversation::factory()->create([
            'visitor_name' => 'Ali bin Abu',
            'visitor_email' => 'ali@example.com',
            'visitor_phone' => '60123456789',
            'meta' => ['fields' => [[
                'key' => 'services',
                'label' => 'Service',
                'value' => 'Perkhidmatan / Visa',
                'path' => [
                    ['key' => 'perkhidmatan', 'label' => 'Perkhidmatan'],
                    ['key' => 'visa', 'label' => 'Visa'],
                ],
            ]]],
        ]);

        $template = 'https://crm.test/create?case_id=livechat-{id}&customer_name={name}'
            .'&patient_email={email}&patient_phone1={phone}'
            .'&category={field:services:1}&sub_category1={field:services:2}&full={field:services}';

        $url = $this->resolve($template, $conversation);

        $this->assertStringContainsString('case_id=livechat-'.$conversation->id, $url);
        $this->assertStringContainsString('customer_name=Ali%20bin%20Abu', $url);
        $this->assertStringContainsString('patient_email=ali%40example.com', $url);
        $this->assertStringContainsString('patient_phone1=60123456789', $url);
        $this->assertStringContainsString('category=Perkhidmatan', $url);
        $this->assertStringContainsString('sub_category1=Visa', $url);
        $this->assertStringContainsString('full=Perkhidmatan%20%2F%20Visa', $url);
    }

    public function test_it_resolves_the_public_transcript_url(): void
    {
        config(['app.frontend_url' => 'https://app.test']);
        $conversation = Conversation::factory()->create();

        $url = $this->resolve('https://crm.test/?chat={transcript}', $conversation);

        $expected = rawurlencode("https://app.test/preview/{$conversation->uuid}");
        $this->assertSame("https://crm.test/?chat={$expected}", $url);
    }

    public function test_missing_values_resolve_to_empty_and_blank_templates_to_null(): void
    {
        $conversation = Conversation::factory()->create(['visitor_name' => null, 'meta' => null]);

        $this->assertSame(
            'https://x.test/?n=&c=',
            $this->resolve('https://x.test/?n={name}&c={field:nope:1}', $conversation),
        );
        $this->assertNull($this->resolve(null, $conversation));
        $this->assertNull($this->resolve('   ', $conversation));
    }

    public function test_agent_conversation_show_returns_the_resolved_cms_url(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $agent = Agent::factory()->for(User::factory()->for(Role::where('name', 'agent')->first()))->create();
        $inbox = Inbox::factory()->create([
            'channel_config' => ['cms_url' => 'https://crm.test/?case_id=livechat-{id}&name={name}'],
        ]);
        $conversation = Conversation::factory()->active()->for($inbox)->create([
            'assigned_agent_id' => $agent->id,
            'visitor_name' => 'Ali Abu',
        ]);

        Sanctum::actingAs($agent->user);

        $this->getJson("/api/v1/agent/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonPath('data.inbox.cms_url', "https://crm.test/?case_id=livechat-{$conversation->id}&name=Ali%20Abu");
    }

    public function test_the_public_widget_never_receives_the_cms_url(): void
    {
        $inbox = Inbox::factory()->create([
            'channel_config' => ['cms_url' => 'https://crm.test/?id={id}'],
        ]);
        $conversation = Conversation::factory()->active()->for($inbox)->create();

        $this->getJson("/api/v1/public/conversations/{$conversation->uuid}")
            ->assertOk()
            ->assertJsonMissingPath('data.inbox.cms_url');
    }
}
