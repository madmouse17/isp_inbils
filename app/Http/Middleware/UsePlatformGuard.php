<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes `platform` the default guard on every operator-panel route, guest ones included. `auth:platform` does it
 * only on authenticated routes; without this, a login failure on the operator plane would name a tenant user who
 * shares the session cookie as its causer, and stamp that tenant's company on the row. The previous default guard
 * comes back after the response, so a long-lived worker or a test process never carries it into the next request.
 */
final class UsePlatformGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = Auth::getDefaultDriver();
        Auth::shouldUse('platform');

        try {
            return $next($request);
        } finally {
            Auth::shouldUse($previous);
        }
    }
}
