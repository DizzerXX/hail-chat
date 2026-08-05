<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\Agent;
use App\Models\AuditLog;
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

        AuditLog::record($request->user(), 'user.created', $user, "Created user {$user->email}");

        return AdminUserResource::make($user->load(['role', 'agent.teams']))
            ->response()
            ->setStatusCode(201);
    }

    /** Full details + last login for the users-list "View" dialog. */
    public function show(User $user): JsonResponse
    {
        $lastLogin = AuditLog::where('user_id', $user->id)
            ->where('action', 'login')
            ->latest('id')
            ->value('created_at');

        return response()->json(['data' => [
            ...AdminUserResource::make($user->load(['role', 'agent.teams']))->resolve(),
            'last_login_at' => $lastLogin,
        ]]);
    }

    /** That user's account/system audit trail (most recent first). */
    public function activity(User $user): JsonResponse
    {
        $entries = AuditLog::where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $entry) => [
                'action' => $entry->action,
                'description' => $entry->description,
                'ip_address' => $entry->ip_address,
                'created_at' => $entry->created_at,
            ]);

        return response()->json(['data' => $entries]);
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

        $deactivated = $request->validated('is_active', $user->is_active) === false;
        AuditLog::record(
            $request->user(),
            $deactivated ? 'user.deactivated' : 'user.updated',
            $user,
            ($deactivated ? 'Deactivated user ' : 'Updated user ').$user->email,
        );

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
            // Removing the agent profile is a deliberate "no longer an agent",
            // so the license goes back to the pool (the FK nulls on delete).
            // Merely deactivating a user does not — that binding is kept.
            $user->agent?->delete();

            return;
        }

        $agent = Agent::updateOrCreate(
            ['user_id' => $user->id],
            ['display_name' => $profile['display_name'] ?? null],
        );

        $agent->teams()->sync($profile['team_ids'] ?? []);

        $this->assertLicensed($user, $agent);
    }

    /**
     * One license activates one agent. An agent who already holds one keeps it
     * through deactivation, so re-activating them needs no new license.
     */
    private function assertLicensed(User $user, Agent $agent): void
    {
        if (! license()->configured() || ! $user->is_active) {
            return;
        }

        if (license()->assign($agent) === null) {
            abort(422, sprintf(
                'No licenses available: %d of %d in use. Sync the license platform, add licenses, or release one from another agent.',
                license()->seatsUsed(),
                license()->capacity(),
            ));
        }
    }
}
