<?php

namespace App\Http\Middleware;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drops a switched-into company the user may no longer act in (membership revoked, company
 * deactivated, or a forged session value) so the session heals to the home company. The tenant
 * scope never trusts the session either: CompanyService validates it on every read.
 */
class ResolveActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->hasSession()) {
            CompanyService::forgetResolution();

            $switched = $request->session()->get(CompanyService::SESSION_KEY);

            if ($switched !== null && CompanyService::idFor($user) !== (int) $switched) {
                $request->session()->forget(CompanyService::SESSION_KEY);
            }
        }

        return $next($request);
    }
}
