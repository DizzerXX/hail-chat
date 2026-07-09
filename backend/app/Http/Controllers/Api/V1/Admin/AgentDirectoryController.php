<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use Illuminate\Http\JsonResponse;

class AgentDirectoryController extends Controller
{
    /**
     * Picker source for reroute and team-membership dialogs.
     */
    public function index(): JsonResponse
    {
        $agents = Agent::with(['user:id,name', 'teams:id'])
            ->get()
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'display_name' => $agent->displayName(),
                'user_name' => $agent->user->name,
                'status' => $agent->status,
                'team_ids' => $agent->teams->pluck('id'),
            ]);

        return response()->json(['data' => $agents]);
    }
}
