<?php

namespace App\Http\Middleware\Platform;

use App\Models\PlatformAdmin;
use App\Support\PlatformSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory on the platform (alias platform.2fa). An admin who has not enrolled yet
 * is confined to the enrolment routes. An enrolled admin must have passed the challenge, or enrolled, in THIS
 * session: a session opened before another session enrolled the account (so it never met the challenge) is ended
 * here and goes back to the login, so enrolling elsewhere never unlocks a stale session.
 */
class RequirePlatformTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        if (! $admin->hasTwoFactorEnabled()) {
            return redirect()->route('platform.two-factor.setup');
        }

        if (! is_int($request->session()->get(PlatformSession::TWO_FACTOR_VERIFIED))) {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('platform.login');
        }

        return $next($request);
    }
}
