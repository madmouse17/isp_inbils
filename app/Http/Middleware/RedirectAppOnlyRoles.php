<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Technicians and customers use the Android apps. A login whose roles are only those two is sent to the
 * "use the app" page from every admin route, a redirect rather than a 403 so a bookmarked link stays friendly.
 * Anyone who also holds another role (admin, manager, staff, ...) is unaffected.
 */
class RedirectAppOnlyRoles
{
    public const APP_ROLES = ['technician', 'customer'];

    public static function isAppOnly(User $user): bool
    {
        $roles = $user->getRoleNames();

        return $roles->isNotEmpty() && $roles->diff(self::APP_ROLES)->isEmpty();
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
