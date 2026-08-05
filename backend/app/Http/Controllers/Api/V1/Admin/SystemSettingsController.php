<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SystemSettingsRequest;
use App\Models\AuditLog;
use App\Models\SystemSettings;
use Illuminate\Http\JsonResponse;

class SystemSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(SystemSettingsRequest $request): JsonResponse
    {
        SystemSettings::current()->update($request->validated());
        SystemSettings::forgetCurrent();

        AuditLog::record($request->user(), 'settings.updated', null, 'Updated system settings');

        return response()->json(['data' => $this->payload()]);
    }

    private function payload(): array
    {
        $settings = SystemSettings::current();

        return [
            ...$settings->only([
                'app_name',
                'favicon',
                'logo',
                'retention_enabled',
                'retention_days',
            ]),
            'retention_min_days' => (int) config('system.retention_min_days', 7),
            'last_purge_at' => $settings->last_purge_at,
            'last_purge_count' => $settings->last_purge_count,
        ];
    }
}
