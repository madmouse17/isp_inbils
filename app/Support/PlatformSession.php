<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;

/**
 * The session keys and time limits of a platform admin's login (/platform), in one place so the middleware, the
 * controllers and the impersonation layer read the same names. All keys are namespaced `platform.` and live in the
 * same server-side session as the tenant guard; the tenant keys (`auth.password_confirmed_at`, `login.two_factor`)
 * are deliberately different, so neither plane can satisfy the other's check.
 */
final class PlatformSession
{
    /** Unix timestamp of the last accepted request; the idle timeout is measured from it. */
    public const LAST_ACTIVITY = 'platform.last_activity_at';

    /** Unix timestamp set only by this session's own challenge or enrolment; enrolment in another session never sets it here. */
    public const TWO_FACTOR_VERIFIED = 'platform.two_factor_verified_at';

    /** Unix timestamp of the last platform password confirmation (step-up for sensitive actions). */
    public const PASSWORD_CONFIRMED = 'platform.auth.password_confirmed_at';

    /** A session idle for longer than this ends. */
    public const IDLE_SECONDS = 1800;

    /** How long a password confirmation covers sensitive actions. */
    public const PASSWORD_CONFIRM_SECONDS = 300;

    /**
     * The moment the session's last password confirmation stops covering sensitive actions, or null when it has none or
     * has run out. The step-up middleware enforces it and the panel shows it, so both read the same window.
     */
    public static function confirmedUntil(Session $session): ?Carbon
    {
        $confirmedAt = $session->get(self::PASSWORD_CONFIRMED);

        if (! is_int($confirmedAt) || now()->getTimestamp() - $confirmedAt > self::PASSWORD_CONFIRM_SECONDS) {
            return null;
        }

        return Carbon::createFromTimestamp($confirmedAt + self::PASSWORD_CONFIRM_SECONDS);
    }
}
