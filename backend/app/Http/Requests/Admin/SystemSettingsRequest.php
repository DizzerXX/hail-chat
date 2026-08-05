<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SystemSettingsRequest extends FormRequest
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
        $min = (int) config('system.retention_min_days', 7);

        return [
            'app_name' => ['required', 'string', 'max:120'],
            // An image URL or an uploaded image inlined as a data-URI (hence the
            // generous limit); blank clears it back to the default icon.
            'favicon' => ['nullable', 'string', 'max:500000'],
            'logo' => ['nullable', 'string', 'max:1000000'],

            'retention_enabled' => ['required', 'boolean'],
            // Guarded by a hard floor so enabling retention can't purge recent
            // conversations by accident.
            'retention_days' => ['required', 'integer', "min:{$min}", 'max:3650'],
        ];
    }
}
