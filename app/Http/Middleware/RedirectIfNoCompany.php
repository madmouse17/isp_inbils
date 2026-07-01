<?php

namespace App\Http\Middleware;

use App\Models\Core\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNoCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && (Company::query()->count() === 0 || $user->company_id === null)) {
            return redirect('/setup');
        }

        return $next($request);
    }

    private function shouldSkip(Request $request): bool
    {
        return $request->is('setup*')
            || $request->is('logout')
            || $request->is('api/*')
            || $request->is('verification*')
            || $request->routeIs('verification.*')
            || $request->is('login')
            || $request->is('password*');
    }
}
