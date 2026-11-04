<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    /**
     * Active inboxes the agent app subscribes to (inbox.{id} channels)
     * and filters by.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Inbox::where('is_active', true)->orderBy('name');

        // Agents subscribe to inbox.{id} channels from this list, so it must
        // not name inboxes their teams do not work.
        $agent = $request->user()->agent;

        if ($agent !== null && ! $request->user()->hasPermission('conversations.view_all')) {
            $query->whereIn('id', $agent->workableInboxIds());
        }

        return response()->json([
            'data' => $query->get(['id', 'name', 'slug', 'channel_type']),
        ]);
    }
}
