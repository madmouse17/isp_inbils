<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ConfirmablePasswordController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Show the confirm password view.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/ConfirmPassword');
    }

    /**
     * Confirm the user's password. This step guards password changes and two-factor management, so with a
     * stolen session it must not be a free place to guess the password: wrong guesses are limited per user
     * and address, counted before the password is checked, and the right password clears the count.
     */
    public function store(Request $request): RedirectResponse
    {
        $key = 'password-confirm|'.$request->user()->getAuthIdentifier().'|'.$request->ip();

        if (RateLimiter::hit($key) > self::MAX_ATTEMPTS) {
            event(new Lockout($request));

            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'password' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        RateLimiter::clear($key);

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }
}
