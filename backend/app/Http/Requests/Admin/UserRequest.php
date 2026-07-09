<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
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
        $creating = $this->route('user') === null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:filter',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:255'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'is_active' => ['sometimes', 'boolean'],
            'agent' => ['sometimes', 'nullable', 'array'],
            'agent.display_name' => ['nullable', 'string', 'max:255'],
            'agent.max_concurrent_chats' => ['nullable', 'integer', 'min:1', 'max:50'],
            'agent.team_ids' => ['nullable', 'array'],
            'agent.team_ids.*' => ['integer', 'exists:teams,id'],
        ];
    }
}
