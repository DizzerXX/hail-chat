<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChatSettingsRequest;
use App\Models\ChatSettings;
use Illuminate\Http\JsonResponse;

class ChatSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(ChatSettingsRequest $request): JsonResponse
    {
        ChatSettings::current()->update($request->validated());
        ChatSettings::forgetCurrent();

        return response()->json(['data' => $this->payload()]);
    }

    private function payload(): array
    {
        $settings = ChatSettings::current();

        return [
            ...$settings->only([
                'idle_enabled',
                'customer_warn1_minutes',
                'customer_warn2_minutes',
                'customer_close_minutes',
                'agent_idle_minutes',
                'bubble_color',
                'notification_sound',
                'warn1_ms', 'warn1_en',
                'warn2_ms', 'warn2_en',
                'closed_ms', 'closed_en',
                ...ChatSettings::BRANDING,
                ...ChatSettings::FAQ_CONFIG,
                ...ChatSettings::QUEUE_CONFIG,
                ...ChatSettings::FIELD_CONFIG,
            ]),
            // Options the settings screen renders the sound picker from.
            'available_sounds' => config('chat.notification_sounds'),
            'warning_sound' => config('chat.warning_sound'),
        ];
    }
}
