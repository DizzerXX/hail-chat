<?php

namespace Tests\Feature\Admin;

use App\Models\ChatSettings;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        ChatSettings::forgetCurrent();
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'admin')->first())->create());
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idle_enabled' => true,
            'customer_warn1_minutes' => 5,
            'customer_warn2_minutes' => 8,
            'customer_close_minutes' => 10,
            'agent_idle_minutes' => 2,
            'bubble_color' => '#2f80ed',
            'notification_sound' => 'notification-2.mp3',
            'warn1_ms' => 'a', 'warn1_en' => 'a',
            'warn2_ms' => 'b', 'warn2_en' => 'b',
            'closed_ms' => 'c', 'closed_en' => 'c',
            'widget_title' => 'Chat with us',
            'widget_subtitle' => 'TDEChat',
            'widget_header_icon' => '',
            'widget_launcher_icon' => '💬',
            'widget_bot_name' => 'Bot',
            'widget_name_label' => 'Your name (optional)',
            'widget_language_label' => 'Language',
            'widget_message_label' => 'How can we help?',
            'widget_greeting_placeholder' => 'Taip mesej anda',
            'widget_start_label' => 'Start chat',
            'widget_input_placeholder' => 'Type a message',
            'faq_deflection_enabled' => true,
            'widget_faq_title' => 'Quick answers',
            'widget_faq_prompt' => "Didn't find what you were looking for?",
            'widget_faq_button' => 'Chat with a live agent',
            'field_name_enabled' => true,
            'field_name_required' => false,
            'field_email_enabled' => false,
            'field_email_required' => false,
            'field_phone_enabled' => false,
            'field_phone_required' => false,
            'widget_email_label' => 'Email',
            'widget_phone_label' => 'Phone',
            'custom_fields' => [],
        ], $overrides);
    }

    public function test_show_returns_settings_and_sound_options(): void
    {
        $this->getJson('/api/v1/admin/chat-settings')
            ->assertOk()
            ->assertJsonPath('data.bubble_color', '#2f80ed')
            ->assertJsonPath('data.warning_sound', 'warning.mp3')
            ->assertJsonPath('data.available_sounds.0', 'notification-1.mp3');
    }

    public function test_update_persists_and_takes_effect(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'bubble_color' => '#ff8800',
            'notification_sound' => 'notification-3.mp3',
        ]))->assertOk()->assertJsonPath('data.bubble_color', '#ff8800');

        ChatSettings::forgetCurrent();
        $this->assertSame('#ff8800', ChatSettings::current()->bubble_color);
        $this->assertSame('notification-3.mp3', ChatSettings::current()->notification_sound);
    }

    public function test_warning_thresholds_must_increase(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'customer_warn2_minutes' => 4, // not greater than warn1 (5)
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_warn2_minutes');

        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'customer_close_minutes' => 7, // not greater than warn2 (8)
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_close_minutes');
    }

    public function test_bubble_color_and_sound_are_validated(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload(['bubble_color' => 'blue']))
            ->assertUnprocessable()->assertJsonValidationErrors('bubble_color');

        $this->putJson('/api/v1/admin/chat-settings', $this->payload(['notification_sound' => 'evil.exe']))
            ->assertUnprocessable()->assertJsonValidationErrors('notification_sound');
    }

    public function test_requires_the_settings_permission(): void
    {
        Sanctum::actingAs(User::factory()->for(Role::where('name', 'agent')->first())->create());

        $this->getJson('/api/v1/admin/chat-settings')->assertStatus(403);
    }

    public function test_public_app_config_exposes_color_and_widget_branding(): void
    {
        ChatSettings::current()->update([
            'bubble_color' => '#123456',
            'widget_title' => 'Talk to Sales',
            'widget_bot_name' => 'Aida',
        ]);
        ChatSettings::forgetCurrent();

        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.bubble_color', '#123456')
            ->assertJsonPath('data.widget.widget_title', 'Talk to Sales')
            ->assertJsonPath('data.widget.widget_bot_name', 'Aida')
            // Admin-only fields must not leak to the public config.
            ->assertJsonMissingPath('data.notification_sound')
            ->assertJsonMissingPath('data.widget.warn1_en');
    }

    public function test_widget_branding_persists_and_the_title_is_required(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'widget_title' => 'Sokongan',
            'widget_launcher_icon' => '🛎️',
        ]))->assertOk()->assertJsonPath('data.widget_title', 'Sokongan');

        ChatSettings::forgetCurrent();
        $this->assertSame('Sokongan', ChatSettings::current()->widget_title);

        $this->putJson('/api/v1/admin/chat-settings', $this->payload(['widget_title' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('widget_title');
    }

    public function test_custom_fields_persist_and_a_select_needs_options(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'custom_fields' => [
                ['key' => 'order_no', 'label' => 'Order number', 'type' => 'text', 'required' => true],
                ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['Basic', 'Pro']],
            ],
        ]))->assertOk()->assertJsonPath('data.custom_fields.1.options.1', 'Pro');

        ChatSettings::forgetCurrent();
        $this->assertCount(2, ChatSettings::current()->custom_fields);

        // A select with no options is rejected.
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'custom_fields' => [['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('custom_fields.0.options');

        // Bad keys are rejected.
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'custom_fields' => [['key' => 'Bad Key!', 'label' => 'x', 'type' => 'text', 'required' => false]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('custom_fields.0.key');
    }

    public function test_an_uploaded_icon_data_uri_persists(): void
    {
        // A downscaled icon inlined as a data-URI is far longer than varchar(255).
        $dataUri = 'data:image/png;base64,'.str_repeat('A', 8000);

        $this->putJson('/api/v1/admin/chat-settings', $this->payload(['widget_launcher_icon' => $dataUri]))
            ->assertOk()
            ->assertJsonPath('data.widget_launcher_icon', $dataUri);

        ChatSettings::forgetCurrent();
        $this->assertSame($dataUri, ChatSettings::current()->widget_launcher_icon);

        // And it's exposed to the widget via app-config.
        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.widget.widget_launcher_icon', $dataUri);
    }

    public function test_cascade_field_requires_a_valid_tree(): void
    {
        $tree = [
            ['key' => 'perkhidmatan', 'label' => 'Perkhidmatan', 'children' => [
                ['key' => 'visa', 'label' => 'Visa'],
            ]],
            ['key' => 'semakan', 'label' => 'Semakan Agensi'],
        ];

        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'custom_fields' => [['key' => 'services', 'label' => 'Service', 'type' => 'cascade', 'required' => true, 'tree' => $tree]],
        ]))->assertOk()->assertJsonPath('data.custom_fields.0.tree.0.children.0.label', 'Visa');

        // Missing tree.
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'custom_fields' => [['key' => 'services', 'label' => 'Service', 'type' => 'cascade', 'required' => true]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('custom_fields.0.tree');

        // A node without a label.
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'custom_fields' => [['key' => 'services', 'label' => 'Service', 'type' => 'cascade', 'required' => true, 'tree' => [['key' => 'x']]]],
        ]))->assertUnprocessable()->assertJsonValidationErrors('custom_fields.0.tree');
    }

    public function test_faq_deflection_persists_and_is_exposed_to_the_widget(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload([
            'faq_deflection_enabled' => false,
            'widget_faq_title' => 'Soalan lazim',
            'widget_faq_button' => 'Sembang dengan ejen',
        ]))->assertOk()->assertJsonPath('data.widget_faq_title', 'Soalan lazim');

        ChatSettings::forgetCurrent();
        $this->assertFalse(ChatSettings::current()->faq_deflection_enabled);

        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.faq.enabled', false)
            ->assertJsonPath('data.faq.title', 'Soalan lazim')
            ->assertJsonPath('data.faq.button', 'Sembang dengan ejen');
    }

    public function test_faq_copy_is_required(): void
    {
        $this->putJson('/api/v1/admin/chat-settings', $this->payload(['widget_faq_button' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors('widget_faq_button');
    }

    public function test_public_app_config_exposes_the_fields_block(): void
    {
        ChatSettings::current()->update([
            'field_email_enabled' => true,
            'field_email_required' => true,
            'custom_fields' => [['key' => 'order_no', 'label' => 'Order number', 'type' => 'text', 'required' => true]],
        ]);
        ChatSettings::forgetCurrent();

        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.fields.email.enabled', true)
            ->assertJsonPath('data.fields.email.required', true)
            ->assertJsonPath('data.fields.custom.0.key', 'order_no');
    }
}
