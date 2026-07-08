<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RerouteConversationRequest extends FormRequest
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
            'team_id' => ['required_without:agent_id', 'prohibits:agent_id', 'integer', 'exists:teams,id'],
            'agent_id' => ['required_without:team_id', 'prohibits:team_id', 'integer', 'exists:agents,id'],
        ];
    }
}
