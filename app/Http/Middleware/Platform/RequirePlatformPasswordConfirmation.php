<?php

namespace App\Http\Middleware\Platform;

use App\Support\PlatformSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up for sensitive platform actions (alias platform.password.confirm): the platform password must have been
 * confirmed in the last 5 minutes. Laravel's own `password.confirm` cannot serve here: it reads the tenant key
 * `auth.password_confirmed_at` and confirms against the default guard, so one plane's confirmation would
 * satisfy the other's. This one has its own session key, controller and limiter.
 */
class RequirePlatformPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get(PlatformSession::PASSWORD_CONFIRMED);

        if (is_int($confirmedAt) && now()->getTimestamp() - $confirmedAt <= PlatformSession::PASSWORD_CONFIRM_SECONDS) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Konfirmasi kata sandi diperlukan.'], 423);
        }

        return redirect()->guest(route('platform.password.confirm'));
    }
}
