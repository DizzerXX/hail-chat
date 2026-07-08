<?php

namespace App\Http\Requests;

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
        return [
            'message' => ['required', 'string', 'max:4000'],
            'visitor_name' => ['nullable', 'string', 'max:255'],
            'visitor_email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'visitor_phone' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', Rule::in(config('tdechat.languages'))],
            'meta' => ['nullable', 'array'],
        ];
    }
}
