<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ChatSettings;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /**
     * Theme values the embeddable widget reads on load. Unauthenticated and
     * intentionally minimal — only what a public page may safely see.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'bubble_color' => ChatSettings::current()->bubble_color,
        ]]);
    }
}
