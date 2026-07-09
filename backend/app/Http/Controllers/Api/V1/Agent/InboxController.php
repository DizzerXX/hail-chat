<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\Inbox;
use Illuminate\Http\JsonResponse;

class InboxController extends Controller
{
    /**
     * Active inboxes the agent app subscribes to (inbox.{id} channels)
     * and filters by.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Inbox::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'channel_type']),
        ]);
    }
}
