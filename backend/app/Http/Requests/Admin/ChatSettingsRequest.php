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
            // Emoji, an image URL, or an uploaded image inlined as a data-URI
            // (hence the generous limit).
            'widget_header_icon' => ['nullable', 'string', 'max:500000'],
            'widget_launcher_icon' => ['required', 'string', 'max:500000'],
            'widget_bot_name' => ['required', 'string', 'max:60'],
            'widget_name_label' => ['required', 'string', 'max:120'],
            'widget_language_label' => ['required', 'string', 'max:120'],
            'widget_message_label' => ['required', 'string', 'max:120'],
            'widget_greeting_placeholder' => ['required', 'string', 'max:200'],
            'widget_start_label' => ['required', 'string', 'max:60'],
            'widget_input_placeholder' => ['required', 'string', 'max:120'],

            // Pre-chat fields — tier 1 toggles/labels.
            'field_name_enabled' => ['required', 'boolean'],
            'field_name_required' => ['required', 'boolean'],
            'field_email_enabled' => ['required', 'boolean'],
            'field_email_required' => ['required', 'boolean'],
            'field_phone_enabled' => ['required', 'boolean'],
            'field_phone_required' => ['required', 'boolean'],
            'widget_email_label' => ['required', 'string', 'max:120'],
            'widget_phone_label' => ['required', 'string', 'max:120'],

            // Tier 2 — custom fields.
            'custom_fields' => ['present', 'array', 'max:20'],
            'custom_fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'distinct', 'max:40'],
            'custom_fields.*.label' => ['required', 'string', 'max:120'],
            'custom_fields.*.type' => ['required', Rule::in(['text', 'email', 'phone', 'number', 'select', 'cascade'])],
            'custom_fields.*.required' => ['required', 'boolean'],
            'custom_fields.*.options' => ['array'],
            'custom_fields.*.options.*' => ['required', 'string', 'max:120'],
            // Cascade (dependent-dropdown) tree: [{key,label,children?:[...]}]
            'custom_fields.*.tree' => ['array'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('custom_fields', []) as $i => $field) {
                $type = $field['type'] ?? null;

                if ($type === 'select' && empty($field['options'])) {
                    $validator->errors()->add("custom_fields.$i.options", 'A select field needs at least one option.');
                }

                if ($type === 'cascade' && ! $this->isValidTree($field['tree'] ?? null)) {
                    $validator->errors()->add("custom_fields.$i.tree", 'A cascade field needs a valid tree of {key,label,children?} nodes.');
                }
            }
        });
    }

    /** A non-empty list of nodes, each with a string key + label and (optionally) a valid child list. */
    private function isValidTree(mixed $nodes): bool
    {
        if (! is_array($nodes) || $nodes === []) {
            return false;
        }

        foreach ($nodes as $node) {
            if (! is_array($node)
                || ! isset($node['key'], $node['label'])
                || ! is_string($node['key']) || $node['key'] === ''
                || ! is_string($node['label']) || $node['label'] === '') {
                return false;
            }
            if (isset($node['children']) && $node['children'] !== []
                && ! $this->isValidTree($node['children'])) {
                return false;
            }
        }

        return true;
    }

    public function messages(): array
    {
        return [
            'customer_warn2_minutes.gt' => 'The second warning must come after the first.',
            'customer_close_minutes.gt' => 'Auto-close must come after the second warning.',
        ];
    }
}
