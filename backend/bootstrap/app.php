<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'license.valid' => \App\Http\Middleware\EnsureLicenseValid::class,
            'seat.valid' => \App\Http\Middleware\EnsureAgentSeat::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Upstream MiCC failures surface as 502 without leaking internals;
        // the original exception still reaches the log via the reporter.
        $exceptions->render(function (\App\Exceptions\MitelApiException $exception) {
            return response()->json(['message' => 'Upstream contact-center service error.'], 502);
        });
    })->create();
