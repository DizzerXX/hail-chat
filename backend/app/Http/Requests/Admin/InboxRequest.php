<?php

namespace App\Http\Requests\Admin;

use App\Enums\ChannelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InboxRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique('inboxes', 'slug')->ignore($this->route('inbox')),
            ],
            'channel_type' => ['required', Rule::enum(ChannelType::class)],
            'channel_config' => ['nullable', 'array'],
            'channel_config.mitel.queue' => ['nullable', 'string', 'max:255'],
            'channel_config.bot.escalate_on_miss' => ['nullable', 'boolean'],
            // A CMS/CRM URL template — allows {placeholders} (which aren't valid
            // in a strict URL), so require the http(s) scheme rather than url:.
            'channel_config.cms_url' => ['nullable', 'string', 'max:4096', 'regex:/^https?:\/\//i'],
            'bot_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
