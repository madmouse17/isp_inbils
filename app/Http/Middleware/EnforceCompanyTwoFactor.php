<?php

namespace App\Http\Middleware;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company policy "Wajib 2FA untuk admin" (companies.settings.require_admin_two_factor, owner decision K24).
 *
 * While the company the user acts in has switched it on, an admin without a confirmed second factor is sent to the
 * Profile page, where the enrolment is, from every admin page. An admin page is one named `admin.*` or served under
 * `/admin`, named or not: a module may declare a page there without a name, and the lock must not depend on one. An
 * admin is whoever holds one of PRIVILEGED (by permission, so a renamed role still counts). Profile, the two-factor
 * endpoints, the password pages and logout are neither, so the way out of the lock stays open without a list of
 * exceptions. Support staff acting as a user are let through: they passed the platform's own mandatory second factor,
 * and the tenant admin's enrolment is not theirs to make.
 *
 * It sits in the `web` group (bootstrap/app.php), not in one route file, because the modules declare their own
 * admin route groups with the same guards and a policy that left them open would lock nothing.
 */
final class EnforceCompanyTwoFactor
{
    public const SETTING = 'require_admin_two_factor';

    /** What makes an account an admin: it can manage the company, its users or its roles. */
    public const PRIVILEGED = ['company.manage', 'users.manage', 'roles.manage'];

    public const MESSAGE = 'Perusahaan mewajibkan 2FA untuk admin. Aktifkan 2FA untuk melanjutkan.';

    /** Whether the company has the policy on. Stored as a boolean; a 1 or "1" written by hand still counts as on. */
    public static function required(?Company $company): bool
    {
        return filter_var($company->settings[self::SETTING] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** An admin account with no confirmed second factor: the one the policy locks and the prompt nudges. */
    public static function isExposed(User $user): bool
    {
        return ! $user->hasTwoFactorEnabled()
            && $user->getAllPermissions()->pluck('name')->intersect(self::PRIVILEGED)->isNotEmpty();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Name and path are both read as the router resolved them: the path is decoded the way the router decodes it.
        $adminPage = $request->routeIs('admin.*') || $request->is('admin', 'admin/*');

        // The cheap checks first: with the policy off (the default) a request costs no permission lookup.
        if ($user === null
            || ! $adminPage
            || $request->attributes->has('impersonation')
            || ! self::required(CompanyService::current())
            || ! self::isExposed($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::MESSAGE, 'error' => 'two_factor_required'], 403);
        }

        // 303 for a write, so an Inertia PUT/PATCH/DELETE follows the redirect with a GET (this runs before HandleInertiaRequests).
        return redirect()->route('profile.edit', [], $request->isMethodSafe() ? 302 : 303)->with('warning', self::MESSAGE);
    }
}
