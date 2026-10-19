<?php

namespace App\Support;

use App\Services\Core\AuditService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\Models\Activity;

/**
 * Failed sign-ins as a count, not a flood. The first failure of one account (told apart by the hash of the typed
 * address) from one client address in a quarter hour writes the `login.failed` row; every further one only raises its
 * `failures`, so a guessing run costs one row and a cheap update per attempt. Neither the typed secret nor the typed
 * address reaches the row: the address only as a keyed hash, so an unknown address can be followed without being
 * readable. The channel is part of the count: the web, the app and the platform panel each keep their own row, so a
 * tenant user and an operator who share an address never share one.
 */
final class SecurityTrail
{
    /** One counting window: the failures of one account from one address are counted together, and a lockout is recorded once, for this long. */
    public const WINDOW_SECONDS = 900;

    /** Keyed with the app key, so the digest of a guessed address matches nothing outside this installation. */
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    /**
     * @param  'web'|'api'|'platform'  $channel  the platform plane writes to its own log, never to a tenant's `security`
     * @param  Authenticatable|null  $account  the active account the typed address named, null when it named none
     */
    public static function failedLogin(string $email, string $channel, ?Authenticatable $account): void
    {
        $hash = self::emailHash($email);
        // The window number is part of the key, so a window never carries over its count.
        $key = sprintf('security:login-failed:%s|%s|%s|%d', $channel, $hash, request()->ip(), intdiv(now()->getTimestamp(), self::WINDOW_SECONDS));

        Cache::add($key, 0, self::WINDOW_SECONDS);
        $failures = (int) Cache::increment($key);

        if ($failures === 1) {
            $row = AuditService::log(
                $channel === 'platform' ? PlatformAuditService::PLATFORM_LOG : 'security',
                'login.failed',
                ['email_hash' => $hash, 'channel' => $channel, 'failures' => $failures, 'window_minutes' => intdiv(self::WINDOW_SECONDS, 60)],
                $account instanceof Model ? $account : null,
            );
            $rowId = $row instanceof Model ? $row->getKey() : null;

            if ($rowId !== null) {
                Cache::put($key.':row', $rowId, self::WINDOW_SECONDS);
                // Failures that came in while the row was written found no id to update: catch their count up.
                $failures = (int) Cache::get($key, $failures);
            }
        } else {
            $rowId = Cache::get($key.':row');
        }

        // shortcut: two failures racing the write of the first can leave the count one behind until the next failure.
        if ($rowId !== null && $failures > 1) {
            Activity::query()->whereKey($rowId)->update(['properties->failures' => $failures]);
        }
    }
}
