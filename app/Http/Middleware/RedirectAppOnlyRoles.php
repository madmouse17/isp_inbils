<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web back-office needs the `app.web` permission. A login that lacks it in the company it acts in (the technician
 * and customer roles, or any role the company built without web access) is sent to the "use the app" page from every
 * admin route, a redirect rather than a 403 so a bookmarked link stays friendly. A login with no company to act in
 * is not decided here: `require.has.company` sends it to the setup wizard.
 */
class RedirectAppOnlyRoles
{
    public static function isAppOnly(User $user): bool
    {
        return CompanyService::currentId() !== null && ! $user->can('app.web');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && self::isAppOnly($user)) {
            return redirect()->route('app-only');
        }

        return $next($request);
    }
}
