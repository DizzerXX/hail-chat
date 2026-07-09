<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\WorkItems\MitelOpenMediaProvider;
use Illuminate\Http\JsonResponse;

class MitelQueueController extends Controller
{
    public function index(MitelOpenMediaProvider $provider): JsonResponse
    {
        abort_unless(
            (bool) config('mitel.base_url'),
            503,
            'The Mitel integration is not configured.',
        );

        return response()->json(['data' => $provider->listQueues()]);
    }
}
