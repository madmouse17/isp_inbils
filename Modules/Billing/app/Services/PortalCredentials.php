<?php

namespace Modules\Billing\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Credential changes of a customer portal login. Each one also cuts off whatever the old credential opened:
 * the remember-me cookie always, and database sessions (found by user id) where that session driver is used.
 * File and Redis sessions cannot be looked up per user, so a session already open on those drivers survives.
 */
class PortalCredentials
{
    /** The customer finished a password reset from the emailed link: the mailbox is proven and the customer app opens (see ApiTokenService). */
    public static function completeReset(User $user): void
    {
        if ($user->getAttribute('must_change_password')) {
            self::replace($user, ['must_change_password' => false]);
        }
    }

    /** Switches off the phone-number password of a legacy login by replacing it with one nobody knows. */
    public static function rotateLegacyPassword(User $user): void
    {
        self::replace($user, ['password' => Str::password(32)]);
    }

    /** @param  array<string, mixed>  $attributes */
    private static function replace(User $user, array $attributes): void
    {
        $user->forceFill([...$attributes, 'remember_token' => Str::random(60)])->save();

        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
    }
}
