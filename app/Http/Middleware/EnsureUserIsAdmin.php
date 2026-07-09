<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates admin-only API routes (e.g. the KYC approval queue). Must run
 * after auth:sanctum, since it relies on $request->user() already being
 * resolved.
 *
 * Registration note: this needs an alias added in bootstrap/app.php
 * (Laravel 11 config style), e.g.:
 *
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->alias([
 *           'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
 *       ]);
 *   })
 *
 * That file wasn't part of this project's scaffold slice, so it isn't
 * touched here — add the alias manually.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'This action requires admin privileges.',
            ], 403);
        }

        return $next($request);
    }
}
