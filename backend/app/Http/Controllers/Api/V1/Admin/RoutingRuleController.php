<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoutingRuleRequest;
use App\Http\Resources\Admin\RoutingRuleResource;
use App\Models\Inbox;
use App\Models\RoutingRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RoutingRuleController extends Controller
{
    public function index(Inbox $inbox): AnonymousResourceCollection
    {
        return RoutingRuleResource::collection(
            $inbox->routingRules()->with(['team:id,name', 'agent.user:id,name'])
                ->orderBy('priority')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(RoutingRuleRequest $request, Inbox $inbox): JsonResponse
    {
        $rule = $inbox->routingRules()->create($request->validated());

        return RoutingRuleResource::make($rule->load(['team:id,name', 'agent.user:id,name']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(RoutingRuleRequest $request, RoutingRule $rule): RoutingRuleResource
    {
        // team_id/agent_id are mutually exclusive; clear the other side so
        // switching target types honours the DB single-target CHECK.
        $rule->update(array_merge(
            ['team_id' => null, 'agent_id' => null],
            $request->validated(),
        ));

        return RoutingRuleResource::make($rule->load(['team:id,name', 'agent.user:id,name']));
    }

    public function destroy(RoutingRule $rule): Response
    {
        $rule->delete();

        return response()->noContent();
    }
}
