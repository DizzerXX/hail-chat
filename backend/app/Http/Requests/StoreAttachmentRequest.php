<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAttachmentRequest extends FormRequest
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
            'file' => [
                'required',
                'file',
                'max:'.(int) config('attachments.max_kb'),
                Rule::file()->extensions(config('attachments.extensions')),
            ],
            // A caption alongside the file is optional.
            'body' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
