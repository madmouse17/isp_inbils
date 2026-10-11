<?php

namespace App\Http\Middleware;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHasCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || CompanyService::idFor($user) === null) {
            return redirect('/setup');
        }

        return $next($request);
    }
}
