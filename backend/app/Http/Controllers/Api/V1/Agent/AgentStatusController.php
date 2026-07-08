<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\AgentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgentStatusRequest;
use App\Http\Resources\AgentResource;

class AgentStatusController extends Controller
{
    public function update(UpdateAgentStatusRequest $request): AgentResource
    {
        $agent = $request->user()->agent;

        abort_unless($agent !== null, 403, 'This account has no agent profile.');

        $agent->update([
            'status' => AgentStatus::from($request->validated('status')),
            'last_active_at' => now(),
        ]);

        return AgentResource::make($agent);
    }
}
