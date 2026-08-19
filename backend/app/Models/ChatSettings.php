<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single row of admin-editable chat settings. Use ChatSettings::current()
 * to read it — it materialises the row from config defaults on first access.
 */
class ChatSettings extends Model
{
    protected $table = 'chat_settings';

    protected $guarded = [];

    /** Widget branding fields — admin-editable, surfaced to the widget via app-config. */
    public const BRANDING = [
        'widget_title',
        'widget_subtitle',
        'widget_header_icon',
        'widget_launcher_icon',
        'widget_bot_name',
        'widget_name_label',
        'widget_language_label',
        'widget_message_label',
        'widget_greeting_placeholder',
        'widget_start_label',
        'widget_input_placeholder',
    ];

    /** FAQ-deflection screen (toggle + bilingual copy), surfaced to the widget. */
    public const FAQ_CONFIG = [
        'faq_deflection_enabled',
        'faq_skip_bot',
        'widget_faq_title_ms', 'widget_faq_title_en',
        'widget_faq_prompt_ms', 'widget_faq_prompt_en',
        'widget_faq_button_ms', 'widget_faq_button_en',
    ];

    /** Queue & availability: wait-time toggle + bilingual no-agents notice. */
    public const QUEUE_CONFIG = [
        'wait_time_enabled',
        'no_agents_ms', 'no_agents_en',
    ];

    /** Pre-chat field configuration (tier 1 toggles/labels + tier 2 custom fields). */
    public const FIELD_CONFIG = [
        'field_name_enabled',
        'field_name_required',
        'field_email_enabled',
        'field_email_required',
        'field_phone_enabled',
        'field_phone_required',
        'widget_email_label',
        'widget_phone_label',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'idle_enabled' => 'boolean',
            'faq_deflection_enabled' => 'boolean',
            'faq_skip_bot' => 'boolean',
            'wait_time_enabled' => 'boolean',
            'customer_warn1_minutes' => 'integer',
            'customer_warn2_minutes' => 'integer',
            'customer_close_minutes' => 'integer',
            'agent_idle_minutes' => 'integer',
            'field_name_enabled' => 'boolean',
            'field_name_required' => 'boolean',
            'field_email_enabled' => 'boolean',
            'field_email_required' => 'boolean',
            'field_phone_enabled' => 'boolean',
            'field_phone_required' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    private static ?self $current = null;

    public static function current(): self
    {
        return self::$current ??= self::query()->firstOr(
            fn () => self::create(config('chat.defaults')),
        );
    }

    /** Drop the process-level cache (after an update). */
    public static function forgetCurrent(): void
    {
        self::$current = null;
    }

    /** Localised warning text for the given stage ('warn1'|'warn2'|'closed'). */
    public function text(string $stage, string $language): string
    {
        $language = in_array($language, config('tdechat.languages'), true)
            ? $language
            : config('tdechat.default_language');

        return $this->{$stage.'_'.$language};
    }
}
