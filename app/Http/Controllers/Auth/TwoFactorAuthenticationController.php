<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmTwoFactorRequest;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A user managing their own second factor. Every route sits behind `password.confirm`.
 *
 * The setup key, QR code and recovery codes are Inertia flash data: shown on the next page and
 * then gone, never page props, query strings or browser history state. Confirming, regenerating and
 * turning off end the user's other logins and give the acting session a new id (TwoFactorService).
 */
class TwoFactorAuthenticationController extends Controller
{
    /** Start enrolment; the QR code and setup key come back once. */
    public function store(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        Inertia::flash('two_factor', ['setup' => $twoFactor->beginEnrollment($request->user())]);

        return redirect()->route('profile.edit');
    }

    /** Prove the authenticator works; the recovery codes come back once. */
    public function confirm(ConfirmTwoFactorRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $codes = $twoFactor->confirm($request->user(), $request->validated('code'), $request->session());

        if ($codes === null) {
            throw ValidationException::withMessages([
                'code' => __('The provided two-factor code was invalid.'),
            ]);
        }

        Inertia::flash('two_factor', ['recovery_codes' => $codes]);

        return redirect()->route('profile.edit')->with('success', 'Two-factor authentication is on. Keep your recovery codes safe.');
    }

    public function regenerateRecoveryCodes(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $codes = $twoFactor->regenerateRecoveryCodes($request->user(), $request->session());

        Inertia::flash('two_factor', ['recovery_codes' => $codes]);

        return redirect()->route('profile.edit')->with('success', 'New recovery codes generated. The old ones no longer work.');
    }

    /** Turn two-factor authentication off, or cancel a setup that was never confirmed. */
    public function destroy(Request $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $twoFactor->disable($request->user(), $request->session());

        return redirect()->route('profile.edit')->with('success', 'Two-factor authentication is off.');
    }
}
