<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Strict per-seat license enforcement for agent work. An agent whose assigned
 * license has expired (or who holds none while licensing is enforced) is
 * blocked with a distinct "seat_expired" code the dashboard reacts to by
 * logging the agent out — matching one-license-per-agent semantics.
 *
 * Distinct from EnsureLicenseValid (installation-level, code "license_invalid",
 * which admins resolve on the license screen): this one targets the acting
 * agent's own seat and forces a logout, so the two must not share a code.
 *
 * Disabled-open until the license platform is configured, and never bites
 * accounts without an agent profile (admins/supervisors) — they spend no seat.
 */
class EnsureAgentSeat
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! license()->configured()) {
            return $next($request);
        }

        $agent = $request->user()?->agent;

        if ($agent === null || $agent->hasUsableLicense()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Your license seat has expired. Please contact your administrator.',
            'code' => 'seat_expired',
        ], 403);
    }
}
