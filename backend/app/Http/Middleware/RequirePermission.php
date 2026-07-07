<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    /**
     * Abort with 401 for guests, 403 for authenticated users lacking the
     * permission (or deactivated accounts).
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active || ! $user->hasPermission($permission)) {
            abort(Response::HTTP_FORBIDDEN, 'Missing permission: '.$permission);
        }

        return $next($request);
    }
}
