<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireHasCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->company_id === null) {
            return redirect('/setup');
        }

        return $next($request);
    }
}
