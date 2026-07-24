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

    protected function casts(): array
    {
        return [
            'idle_enabled' => 'boolean',
            'customer_warn1_minutes' => 'integer',
            'customer_warn2_minutes' => 'integer',
            'customer_close_minutes' => 'integer',
            'agent_idle_minutes' => 'integer',
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
