<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idle_enabled' => ['required', 'boolean'],
            'customer_warn1_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'customer_warn2_minutes' => ['required', 'integer', 'gt:customer_warn1_minutes', 'max:1440'],
            'customer_close_minutes' => ['required', 'integer', 'gt:customer_warn2_minutes', 'max:1440'],
            'agent_idle_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'bubble_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'notification_sound' => ['required', 'string', Rule::in(config('chat.notification_sounds'))],
            'warn1_ms' => ['required', 'string', 'max:1000'],
            'warn1_en' => ['required', 'string', 'max:1000'],
            'warn2_ms' => ['required', 'string', 'max:1000'],
            'warn2_en' => ['required', 'string', 'max:1000'],
            'closed_ms' => ['required', 'string', 'max:1000'],
            'closed_en' => ['required', 'string', 'max:1000'],

            // Widget branding. Icons accept an emoji or an image URL. Subtitle
            // and header icon may be blank (hidden); the rest need a value.
            'widget_title' => ['required', 'string', 'max:120'],
            'widget_subtitle' => ['nullable', 'string', 'max:120'],
            'widget_header_icon' => ['nullable', 'string', 'max:255'],
            'widget_launcher_icon' => ['required', 'string', 'max:255'],
            'widget_bot_name' => ['required', 'string', 'max:60'],
            'widget_name_label' => ['required', 'string', 'max:120'],
            'widget_language_label' => ['required', 'string', 'max:120'],
            'widget_message_label' => ['required', 'string', 'max:120'],
            'widget_greeting_placeholder' => ['required', 'string', 'max:200'],
            'widget_start_label' => ['required', 'string', 'max:60'],
            'widget_input_placeholder' => ['required', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_warn2_minutes.gt' => 'The second warning must come after the first.',
            'customer_close_minutes.gt' => 'Auto-close must come after the second warning.',
        ];
    }
}
