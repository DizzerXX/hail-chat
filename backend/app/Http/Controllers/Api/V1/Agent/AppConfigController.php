<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\ChatSettings;
use App\Services\Translation\MessageTranslator;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /**
     * Feature flags and theme the dashboard needs before rendering chat tooling.
     */
    public function show(MessageTranslator $translator): JsonResponse
    {
        $settings = ChatSettings::current();

        return response()->json(['data' => [
            'translation' => [
                'enabled' => $translator->enabled(),
                'method' => $translator->method(),
            ],
            'languages' => config('tdechat.languages'),
            'bubble_color' => $settings->bubble_color,
            'notification_sound' => $settings->notification_sound,
            'warning_sound' => config('chat.warning_sound'),
            // Idle thresholds so the agent chat can show a live countdown to the
            // next idle action (mirrors the once-a-minute IdleSweepJob).
            'idle' => [
                'enabled' => $settings->idle_enabled,
                'customer_warn1_minutes' => $settings->customer_warn1_minutes,
                'customer_warn2_minutes' => $settings->customer_warn2_minutes,
                'customer_close_minutes' => $settings->customer_close_minutes,
                'agent_idle_minutes' => $settings->agent_idle_minutes,
            ],
        ]]);
    }
}
