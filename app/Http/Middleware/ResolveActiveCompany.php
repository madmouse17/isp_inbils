<?php

namespace App\Http\Middleware;

use App\Services\Core\CompanyService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Drops a switched-into company the user may no longer act in (membership revoked, company
 * deactivated, or a forged session value) so the session heals to the home company. When the home
 * company is suspended too, the session moves to the first active membership, or is signed out when
 * none is left. The tenant scope never trusts the session either: CompanyService validates it on
 * every read.
 */
class ResolveActiveCompany
{
    public const HEADER = 'X-Inbils-Company';

    public const MOVED_MESSAGE = 'Perusahaan aktif berubah di tab lain.';

    private const TOKEN_EXEMPT = ['logout', 'admin.company.switch'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->hasSession()) {
            CompanyService::forgetResolution();

            $switched = $request->session()->get(CompanyService::SESSION_KEY);

            if ($switched !== null && CompanyService::idFor($user) !== (int) $switched) {
                $request->session()->forget(CompanyService::SESSION_KEY);
            }

            if (CompanyService::isSuspendedFor($user) && ! $request->is('logout')) {
                $fallback = CompanyService::fallbackIdFor($user);

                if ($fallback === null) {
                    Auth::guard('web')->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    // 303, so an Inertia PUT/PATCH/DELETE follows it with a GET (this middleware runs before HandleInertiaRequests).
                    return redirect()->route('login', [], 303)->withErrors(['email' => CompanyService::SUSPENDED_MESSAGE]);
                }

                $request->session()->put(CompanyService::SESSION_KEY, $fallback);
                CompanyService::forgetResolution();
            }

            // A write from a tab opened before another tab switched company would land in the new company: refuse it.
            // No header (classic posts, tests, non-Inertia clients) passes.
            $claimed = $request->header(self::HEADER);

            if (is_string($claimed) && ! $request->isMethodSafe() && ! $request->routeIs(...self::TOKEN_EXEMPT)
                && $claimed !== CompanyService::current()?->public_id) {
                throw new ConflictHttpException(self::MOVED_MESSAGE);
            }
        }

        return $next($request);
    }
}
