<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Platform\Concerns\OpensPlatformSession;
use App\Http\Requests\Platform\Auth\PlatformLoginRequest;
use App\Models\PlatformAdmin;
use App\Services\Core\TwoFactorService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    use OpensPlatformSession;

    public function create(): Response
    {
        return Inertia::render('Platform/Auth/Login', [
            'status' => session('status'),
        ]);
    }

    /**
     * An admin with two-factor authentication on is not logged in here: they are sent to the challenge and
     * logged in once it passes. One without it (first login) is logged in at once but confined to enrolment.
     */
    public function store(PlatformLoginRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $admin = $request->validateCredentials();

        if ($admin->hasTwoFactorEnabled()) {
            $twoFactor->startChallenge($request->session(), $admin, false);

            return redirect()->route('platform.two-factor.login');
        }

        return $this->openPlatformSession($request, $admin, twoFactorPassed: false);
    }

    public function destroy(Request $request, PlatformAuditService $audit): RedirectResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        $audit->platform('auth.logout', ['platform_admin_id' => $admin->id, 'ip' => $request->ip()], $admin, $admin);

        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
