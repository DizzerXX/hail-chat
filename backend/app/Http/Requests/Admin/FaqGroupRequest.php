<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class FaqGroupRequest extends FormRequest
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
        $rules = ['translations' => ['required', 'array']];

        foreach (config('tdechat.languages') as $language) {
            $rules["translations.$language"] = ['sometimes', 'nullable', 'array'];
            $rules["translations.$language.question"] = ["required_with:translations.$language", 'string', 'max:500'];
            $rules["translations.$language.answer"] = ["required_with:translations.$language", 'string', 'max:10000'];
            $rules["translations.$language.keywords"] = ['nullable', 'array', 'max:20'];
            $rules["translations.$language.keywords.*"] = ['string', 'max:100'];
            $rules["translations.$language.is_active"] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Updates may carry only deletions (language => null); the
                // controller guards against removing the last version.
                if ($this->route('translationGroup') !== null) {
                    return;
                }

                $translations = collect($this->input('translations', []))
                    ->only(config('tdechat.languages'))
                    ->filter(fn ($value) => $value !== null);

                if ($translations->isEmpty()) {
                    $validator->errors()->add('translations', 'At least one language version is required.');
                }
            },
        ];
    }
}
