<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoutingRuleRequest extends FormRequest
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
            'priority' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'conditions' => ['nullable', 'array'],
            'conditions.language' => ['nullable', 'string', Rule::in(config('tdechat.languages'))],
            'conditions.keywords' => ['nullable', 'array', 'max:20'],
            'conditions.keywords.*' => ['string', 'max:100'],
            'team_id' => ['required_without:agent_id', 'prohibits:agent_id', 'integer', 'exists:teams,id'],
            'agent_id' => ['required_without:team_id', 'prohibits:team_id', 'integer', 'exists:agents,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
