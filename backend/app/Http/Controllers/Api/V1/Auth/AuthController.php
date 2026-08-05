<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        abort_unless($user->is_active, 403, 'This account is deactivated.');

        $deviceName = $request->validated('device_name');
        $user->tokens()->where('name', $deviceName)->delete();

        AuditLog::record($user, 'login', null, 'Signed in');

        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => UserResource::make($user->load(['role.permissions', 'agent']))->resolve(),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user()->load(['role.permissions', 'agent']));
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
