<?php

namespace App\Http\Controllers\Platform\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\Auth\ConfirmPlatformPasswordRequest;
use App\Models\PlatformAdmin;
use App\Services\Platform\ImpersonationService;
use App\Support\PlatformSession;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
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

        // A stale `url.intended` (the tenant guard leaves one when the operator opened a tenant page as a guest) is no place
        // to land: only a page of this panel counts, and a dialog goes back to the page it posted from (F11-D01).
        $referrer = $this->panelPage($request, url()->previous());
        $intended = $this->panelPage($request, $request->session()->pull('url.intended'));

        if ($referrer !== null && Str::before($referrer, '?') !== route('platform.password.confirm')) {
            return redirect()->to($referrer);
        }

        // The banner of a support session opens the confirm page from a tenant page and names it in return_to. The
        // tenant app is another shell, so the way back is a full page load, not a visit of this one.
        $tenantPage = $referrer === null || $impersonation->current($request->session()) === null ? null : $this->returnTo($referrer);

        return $tenantPage === null
            ? redirect()->to($intended ?? route('platform.dashboard'))
            : Inertia::location(url($tenantPage));
    }

    /** $url when it is a page of this panel on this host, else null: a Referer or a session value is no proof of where to go. */
    private function panelPage(Request $request, mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ($parts['host'] ?? null) !== $request->getHost()) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $panel = route('platform.dashboard', absolute: false);

        return $path === $panel || str_starts_with($path, $panel.'/') ? $url : null;
    }

    /** The tenant page the confirm page URL $confirmPage names in return_to, when it is one. */
    private function returnTo(string $confirmPage): ?string
    {
        parse_str((string) parse_url($confirmPage, PHP_URL_QUERY), $query);

        return ImpersonationService::tenantPage($query['return_to'] ?? null);
    }
}
