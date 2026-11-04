<?php

namespace Tests\Feature;

use App\Models\ChatSettings;
use App\Models\Inbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each inbox may override part of the pre-chat form. Only the keys it sets
 * override; everything else still follows the global ChatSettings, so a global
 * edit keeps reaching inboxes that have not overridden that key.
 */
class PreChatOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function globalForm(array $values = []): void
    {
        ChatSettings::current()->forceFill(array_merge([
            'field_phone_enabled' => false,
            'field_phone_required' => false,
            'widget_email_label' => 'Email',
            'custom_fields' => [
                ['key' => 'topic', 'label' => 'Topic', 'type' => 'text', 'required' => false],
            ],
        ], $values))->save();
    }

    private function inboxWith(array $prechat): Inbox
    {
        return Inbox::factory()->create([
            'slug' => 'eaduan',
            'channel_config' => ['prechat' => $prechat],
        ]);
    }

    public function test_app_config_returns_the_global_form_when_no_inbox_is_named(): void
    {
        $this->globalForm();
        $this->inboxWith(['field_phone_enabled' => true]);

        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.fields.phone.enabled', false)
            ->assertJsonPath('data.fields.custom.0.key', 'topic');
    }

    public function test_an_inbox_override_replaces_only_the_keys_it_sets(): void
    {
        $this->globalForm();
        $this->inboxWith([
            'field_phone_enabled' => true,
            'field_phone_required' => true,
            'custom_fields' => [
                ['key' => 'aduan_jenis', 'label' => 'Jenis aduan', 'type' => 'text', 'required' => true],
            ],
        ]);

        $this->getJson('/api/v1/public/app-config?inbox=eaduan')
            ->assertOk()
            ->assertJsonPath('data.fields.phone.enabled', true)
            ->assertJsonPath('data.fields.phone.required', true)
            // Untouched by the override, so still the global value.
            ->assertJsonPath('data.fields.email.label', 'Email')
            ->assertJsonCount(1, 'data.fields.custom')
            ->assertJsonPath('data.fields.custom.0.key', 'aduan_jenis');
    }

    public function test_an_unknown_inbox_slug_falls_back_to_the_global_form(): void
    {
        $this->globalForm();

        $this->getJson('/api/v1/public/app-config?inbox=nope')
            ->assertOk()
            ->assertJsonPath('data.fields.phone.enabled', false);
    }

    public function test_starting_a_chat_is_validated_against_the_inboxs_own_form(): void
    {
        $this->globalForm();
        $inbox = $this->inboxWith([
            'field_phone_enabled' => true,
            'field_phone_required' => true,
            'custom_fields' => [
                ['key' => 'aduan_jenis', 'label' => 'Jenis aduan', 'type' => 'text', 'required' => true],
            ],
        ]);

        // The global form asks for neither, the inbox's asks for both.
        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Saya nak buat aduan',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['visitor_phone', 'fields.aduan_jenis']);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Saya nak buat aduan',
            'visitor_phone' => '0175195652',
            'fields' => ['aduan_jenis' => 'Passport'],
        ])->assertCreated();
    }

    public function test_the_inboxs_own_answers_are_stored_on_the_conversation(): void
    {
        $this->globalForm();
        $inbox = $this->inboxWith([
            'custom_fields' => [
                ['key' => 'aduan_jenis', 'label' => 'Jenis aduan', 'type' => 'text', 'required' => true],
            ],
        ]);

        $this->postJson("/api/v1/public/inboxes/{$inbox->slug}/conversations", [
            'message' => 'Saya nak buat aduan',
            'fields' => ['aduan_jenis' => 'Passport'],
        ])->assertCreated();

        $meta = $inbox->conversations()->sole()->meta;

        $this->assertSame('aduan_jenis', $meta['fields'][0]['key']);
        $this->assertSame('Jenis aduan', $meta['fields'][0]['label']);
        $this->assertSame('Passport', $meta['fields'][0]['value']);
    }
}
