<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates license-protected areas (the user-management module). Always a
 * clean 403 payload with code "license_invalid" — never a raw 500 — so the
 * dashboard can render its license screen. Disabled-open when the license
 * manager is not configured.
 */
class EnsureLicenseValid
{
    public function handle(Request $request, Closure $next): Response
    {
        if (license()->isValid()) {
            return $next($request);
        }

        return response()->json([
            'message' => license()->problem() ?? 'The license is invalid.',
            'code' => 'license_invalid',
        ], 403);
    }
}
