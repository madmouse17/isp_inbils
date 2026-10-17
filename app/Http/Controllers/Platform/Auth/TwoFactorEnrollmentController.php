<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Models\PlatformAdmin;
use App\Services\Core\TwoFactorService;
use App\Support\PlatformSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A platform admin enrolling, and later managing, their own second factor. It is mandatory and can never be
 * switched off. The setup key, QR code and recovery codes are Inertia flash data: shown on the next page and
 * then gone, never page props, query strings or browser history state.
 *
 * Enrolling and confirming are open to a signed-in admin who has no second factor yet (first login); the
 * recovery-code reset needs the full stack and a recent password confirmation (routes/platform/auth.php).
 */
class TwoFactorEnrollmentController extends Controller
{
    public function show(Request $request, TwoFactorService $twoFactor): Response
    {
        return Inertia::render('Platform/Auth/TwoFactorSetup', [
            'twoFactor' => $twoFactor->status($this->admin($request)),
        ]);
    }

    /** Start enrolment; the QR code and setup key come back once. */
    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        Inertia::flash('two_factor', ['setup' => $twoFactor->beginEnrollment($this->admin($request))]);

        return redirect()->route('platform.two-factor.setup');
    }

    /**
     * Prove the authenticator works; the recovery codes come back once. This session has now passed the second
     * factor itself, which is what platform.2fa looks for.
     */
    public function confirm(ConfirmTwoFactorRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $codes = $twoFactor->confirm($this->admin($request), $request->validated('code'), $request->session());

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => __('The provided two-factor code was invalid.'),
            ]);
        }

        $request->session()->put(PlatformSession::TWO_FACTOR_VERIFIED, now()->getTimestamp());

        Inertia::flash('two_factor', ['recovery_codes' => $codes]);

        return redirect()->route('platform.two-factor.setup')
            ->with('success', 'Autentikasi dua langkah aktif. Simpan kode pemulihan Anda dengan aman.');
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $codes = $twoFactor->regenerateRecoveryCodes($this->admin($request), $request->session());

        Inertia::flash('two_factor', ['recovery_codes' => $codes]);

        return redirect()->route('platform.two-factor.setup')
            ->with('success', 'Kode pemulihan baru dibuat. Kode yang lama tidak berlaku lagi.');
    }

    private function admin(Request $request): PlatformAdmin
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');

        return $admin;
    }
}
