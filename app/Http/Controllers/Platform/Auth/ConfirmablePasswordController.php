<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Auth\ConfirmPlatformPasswordRequest;
use App\Models\PlatformAdmin;
use App\Services\Platform\ImpersonationService;
use App\Support\PlatformSession;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The step-up of the platform panel: confirms the signed-in admin's password against the platform guard and
 * stamps the platform key, never the tenant one (see RequirePlatformPasswordConfirmation).
 */
class ConfirmablePasswordController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show(): Response
    {
        return Inertia::render('Platform/Auth/ConfirmPassword');
    }

    /**
     * Wrong guesses are limited per admin and address, counted before the password is checked, and the right
     * password clears the count: a stolen session must not be a free place to guess the password.
     */
    public function store(ConfirmPlatformPasswordRequest $request, ImpersonationService $impersonation): SymfonyResponse
    {
        /** @var PlatformAdmin $admin */
        $admin = $request->user('platform');
        $key = 'platform-password-confirm|'.$admin->getKey().'|'.$request->ip();

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

        if (! Auth::guard('platform')->validate([
            'email' => $admin->email,
            'password' => $request->validated('password'),
        ])) {
            throw ValidationException::withMessages([
                'password' => 'Kata sandi tidak sesuai.',
            ]);
        }

        RateLimiter::clear($key);

        $request->session()->put(PlatformSession::PASSWORD_CONFIRMED, now()->getTimestamp());

        // A visit the middleware interrupted resumes first. A dialog posts from the page it guards, so otherwise the
        // answer goes back to that page; the confirm page itself is no place to return to.
        $back = url()->previous(route('platform.dashboard'));

        if (Str::before($back, '?') !== route('platform.password.confirm')) {
            return redirect()->intended($back);
        }

        // The banner of a support session opens the confirm page from a tenant page and names it in return_to. The
        // tenant app is another shell, so the way back is a full page load, not a visit of this one.
        $tenantPage = $impersonation->current($request->session()) === null ? null : $this->returnTo($back);

        return $tenantPage === null
            ? redirect()->intended(route('platform.dashboard'))
            : Inertia::location(url($tenantPage));
    }

    /** The tenant page the confirm page URL $confirmPage names in return_to, when it is one. */
    private function returnTo(string $confirmPage): ?string
    {
        parse_str((string) parse_url($confirmPage, PHP_URL_QUERY), $query);

        return ImpersonationService::tenantPage($query['return_to'] ?? null);
    }
}
