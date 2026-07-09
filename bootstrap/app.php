<?php

use App\Http\Middleware\EnsureUserIsAdmin;
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
    ->withMiddleware(function (Middleware $middleware) {
        // 'admin' gates every /api/admin/* route (KYC approval queue,
        // wallet admin adjustments, compliance exports) — see
        // EnsureUserIsAdmin's own docblock, which specifically named
        // this file as the one place it still needed to be wired in.
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Sanctum's stateful-API middleware isn't added here since this
        // app authenticates purely via bearer tokens (see AuthController)
        // rather than Sanctum's cookie-based SPA mode — nothing in
        // routes/api.php relies on a shared session/cookie.
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Framework defaults (JSON error responses for API requests,
        // since every route in this app is under /api) are sufficient
        // for now — nothing here needed a custom renderer as of Phase 9.
    })->create();
