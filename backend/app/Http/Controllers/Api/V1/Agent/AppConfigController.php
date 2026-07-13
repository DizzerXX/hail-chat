<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Services\Translation\MessageTranslator;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /**
     * Feature flags the dashboard needs before rendering chat tooling.
     */
    public function show(MessageTranslator $translator): JsonResponse
    {
        return response()->json(['data' => [
            'translation' => [
                'enabled' => $translator->enabled(),
                'method' => $translator->method(),
            ],
            'languages' => config('tdechat.languages'),
        ]]);
    }
}
