<?php

namespace App\Http\Middleware;

use App\Models\Core\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireNoCompany
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(Company::query()->count() > 0, 403);

        return $next($request);
    }
}
