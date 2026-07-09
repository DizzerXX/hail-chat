<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\Agent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
        ]);

        $users = User::query()
            ->with(['role', 'agent.teams'])
            ->when($validated['search'] ?? null, fn (Builder $query, string $search) => $query->where(
                fn (Builder $query) => $query
                    ->where('name', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%"),
            ))
            ->when($validated['role'] ?? null, fn (Builder $query, string $role) => $query
                ->whereHas('role', fn (Builder $query) => $query->where('name', $role)))
            ->orderBy('name')
            ->paginate(25);

        return AdminUserResource::collection($users);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($request->validated('password')),
                'role_id' => $request->validated('role_id'),
                'is_active' => $request->validated('is_active', true),
            ]);

            $this->syncAgentProfile($user, $request);

            return $user;
        });

        return AdminUserResource::make($user->load(['role', 'agent.teams']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UserRequest $request, User $user): AdminUserResource
    {
        if ($user->is($request->user()) && $request->validated('is_active', true) === false) {
            abort(422, 'You cannot deactivate your own account.');
        }

        DB::transaction(function () use ($request, $user) {
            $user->update(array_filter([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'role_id' => $request->validated('role_id'),
                'is_active' => $request->validated('is_active', $user->is_active),
                'password' => $request->validated('password') !== null
                    ? Hash::make($request->validated('password'))
                    : null,
            ], fn (mixed $value) => $value !== null));

            $this->syncAgentProfile($user, $request);
        });

        return AdminUserResource::make($user->fresh(['role', 'agent.teams']));
    }

    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::orderBy('name')->get(['id', 'name', 'description']),
        ]);
    }

    private function syncAgentProfile(User $user, UserRequest $request): void
    {
        if (! $request->has('agent')) {
            return;
        }

        $profile = $request->validated('agent');

        if ($profile === null) {
            $user->agent?->delete();

            return;
        }

        $agent = Agent::updateOrCreate(
            ['user_id' => $user->id],
            [
                'display_name' => $profile['display_name'] ?? null,
                'max_concurrent_chats' => $profile['max_concurrent_chats'] ?? 5,
            ],
        );

        $agent->teams()->sync($profile['team_ids'] ?? []);
    }
}
