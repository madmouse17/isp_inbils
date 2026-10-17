<?php

namespace App\Http\Controllers\Platform\Concerns;

use App\Models\PlatformAdmin;
use App\Services\Platform\PlatformAuditService;
use App\Support\PlatformSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The end of every platform login: reached after the second factor passed, or straight after the password when
 * the account has none yet (first login).
 */
trait OpensPlatformSession
{
    private function openPlatformSession(Request $request, PlatformAdmin $admin, bool $twoFactorPassed): RedirectResponse
    {
        // login() renews the session id (fixation); the Login event stamps last_login_at.
        Auth::guard('platform')->login($admin);

        $request->session()->put(PlatformSession::LAST_ACTIVITY, now()->getTimestamp());

        if ($twoFactorPassed) {
            $request->session()->put(PlatformSession::TWO_FACTOR_VERIFIED, now()->getTimestamp());
        }

        app(PlatformAuditService::class)->platform('auth.login', [
            'platform_admin_id' => $admin->id,
            'two_factor' => $twoFactorPassed,
            'ip' => $request->ip(),
        ], $admin, $admin);

        // Without a second factor the session may only reach the enrolment routes (platform.2fa sends it there).
        return $twoFactorPassed
            ? redirect()->intended(route('platform.dashboard', absolute: false))
            : redirect()->route('platform.two-factor.setup');
    }
}
