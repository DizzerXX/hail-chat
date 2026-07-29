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

            $required = ($field['required'] ?? false) ? 'required' : 'nullable';
            $type = $field['type'] ?? 'text';

            // A cascade answer is the selected path (a list of node keys).
            if ($type === 'cascade') {
                $rules["fields.$key"] = [$required, 'array'];
                $rules["fields.$key.*"] = ['string', 'max:120'];

                continue;
            }

            $rule = [$required, 'string', 'max:1000'];

            if ($type === 'select' && ! empty($field['options'])) {
                $rule[] = Rule::in($field['options']);
            }
            if ($type === 'email') {
                $rule[] = 'email:filter';
            }

            $rules["fields.$key"] = $rule;
        }

        return $rules;
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            foreach (ChatSettings::current()->custom_fields ?? [] as $field) {
                if (($field['type'] ?? null) !== 'cascade') {
                    continue;
                }

                $key = $field['key'] ?? null;
                $path = $key !== null ? (array) $this->input("fields.$key", []) : [];

                // Empty is handled by the required/nullable rule; a given path
                // must chain valid keys and end at a leaf (a complete choice).
                if ($path !== [] && ! $this->pathReachesLeaf($field['tree'] ?? [], $path)) {
                    $validator->errors()->add("fields.$key", 'Please complete the selection.');
                }
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $path
     */
    private function pathReachesLeaf(array $nodes, array $path): bool
    {
        $current = $nodes;
        $node = null;

        foreach ($path as $selectedKey) {
            $node = collect($current)->firstWhere('key', $selectedKey);
            if ($node === null) {
                return false;
            }
            $current = $node['children'] ?? [];
        }

        return $node !== null && empty($node['children']);
    }
}
