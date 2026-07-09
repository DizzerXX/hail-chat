<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TeamRequest;
use App\Http\Resources\Admin\TeamResource;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TeamController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TeamResource::collection(
            Team::with('agents.user')->orderBy('name')->get()
        );
    }

    public function store(TeamRequest $request): JsonResponse
    {
        $team = Team::create($request->validated());

        return TeamResource::make($team->load('agents.user'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(TeamRequest $request, Team $team): TeamResource
    {
        $team->update($request->validated());

        return TeamResource::make($team->load('agents.user'));
    }

    public function destroy(Team $team): Response
    {
        $team->delete();

        return response()->noContent();
    }

    public function members(Request $request, Team $team): TeamResource
    {
        $validated = $request->validate([
            'agent_ids' => ['present', 'array'],
            'agent_ids.*' => ['integer', 'exists:agents,id'],
        ]);

        $team->agents()->sync($validated['agent_ids']);

        return TeamResource::make($team->load('agents.user'));
    }
}
