<?php

namespace App\Http\Requests;

use App\Models\ChatSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartConversationRequest extends FormRequest
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
        $settings = ChatSettings::current();

        $requiredIf = fn (bool $enabled, bool $required) => $enabled && $required ? 'required' : 'nullable';

        $rules = [
            'message' => ['required', 'string', 'max:4000'],
            'visitor_name' => [$requiredIf($settings->field_name_enabled, $settings->field_name_required), 'string', 'max:255'],
            'visitor_email' => [$requiredIf($settings->field_email_enabled, $settings->field_email_required), 'string', 'email:filter', 'max:255'],
            'visitor_phone' => [$requiredIf($settings->field_phone_enabled, $settings->field_phone_required), 'string', 'max:50'],
            'language' => ['nullable', 'string', Rule::in(config('tdechat.languages'))],
            'meta' => ['nullable', 'array'],
            // Custom field answers, keyed by field key.
            'fields' => ['nullable', 'array'],
        ];

        // Validate each configured custom field against its definition.
        foreach ($settings->custom_fields ?? [] as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $rule = [($field['required'] ?? false) ? 'required' : 'nullable', 'string', 'max:1000'];

            if (($field['type'] ?? 'text') === 'select' && ! empty($field['options'])) {
                $rule[] = Rule::in($field['options']);
            }
            if (($field['type'] ?? 'text') === 'email') {
                $rule[] = 'email:filter';
            }

            $rules["fields.$key"] = $rule;
        }

        return $rules;
    }
}
