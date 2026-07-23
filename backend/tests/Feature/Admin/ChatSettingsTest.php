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

    public function test_public_app_config_exposes_only_the_bubble_color(): void
    {
        ChatSettings::current()->update(['bubble_color' => '#123456']);
        ChatSettings::forgetCurrent();

        $this->getJson('/api/v1/public/app-config')
            ->assertOk()
            ->assertJsonPath('data.bubble_color', '#123456')
            ->assertJsonMissingPath('data.notification_sound');
    }
}
