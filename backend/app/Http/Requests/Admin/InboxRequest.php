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
            // Required: an inbox nobody can work is a silent dead letter box.
            'team_ids' => ['required', 'array', 'min:1'],
            'team_ids.*' => ['integer', 'exists:teams,id'],
            // Per-inbox pre-chat override. Only the keys present override the
            // global form, so a global edit still reaches the rest.
            'channel_config.prechat' => ['nullable', 'array'],
            'channel_config.prechat.field_name_enabled' => ['nullable', 'boolean'],
            'channel_config.prechat.field_name_required' => ['nullable', 'boolean'],
            'channel_config.prechat.field_email_enabled' => ['nullable', 'boolean'],
            'channel_config.prechat.field_email_required' => ['nullable', 'boolean'],
            'channel_config.prechat.field_phone_enabled' => ['nullable', 'boolean'],
            'channel_config.prechat.field_phone_required' => ['nullable', 'boolean'],
            'channel_config.prechat.widget_name_label' => ['nullable', 'string', 'max:120'],
            'channel_config.prechat.widget_email_label' => ['nullable', 'string', 'max:120'],
            'channel_config.prechat.widget_phone_label' => ['nullable', 'string', 'max:120'],
            'channel_config.prechat.custom_fields' => ['nullable', 'array', 'max:20'],
            'channel_config.prechat.custom_fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct', 'max:40'],
            'channel_config.prechat.custom_fields.*.label' => ['required', 'string', 'max:120'],
            'channel_config.prechat.custom_fields.*.type' => ['required', Rule::in(['text', 'email', 'phone', 'number', 'select', 'cascade'])],
            'channel_config.prechat.custom_fields.*.required' => ['required', 'boolean'],
            'channel_config.prechat.custom_fields.*.options' => ['array'],
            'channel_config.prechat.custom_fields.*.options.*' => ['required', 'string', 'max:120'],
            'channel_config.prechat.custom_fields.*.tree' => ['array'],
            'bot_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
