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
