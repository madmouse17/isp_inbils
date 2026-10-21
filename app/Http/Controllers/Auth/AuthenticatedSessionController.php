<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Concerns\OpensSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Core\SettingService;
use App\Services\Core\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    use OpensSession;

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // Set once by NewPasswordController after a customer saved a password: the sign-in is in the app.
            'customerHandoff' => session()->has('customer_handoff') ? [
                'email' => session('customer_handoff'),
                'appUrl' => SettingService::playStoreUrl('customer'),
            ] : null,
        ]);
    }

    /**
     * Handle an incoming authentication request. A user with two-factor authentication on is not
     * logged in here: they are sent to the challenge and logged in once it passes.
     */
    public function store(LoginRequest $request, TwoFactorService $twoFactor): RedirectResponse
    {
        $user = $request->validateCredentials();

        if ($user->hasTwoFactorEnabled()) {
            $twoFactor->startChallenge($request->session(), $user, $request->boolean('remember'));

            return redirect()->route('two-factor.login');
        }

        return $this->openSession($request, $user, $request->boolean('remember'));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
