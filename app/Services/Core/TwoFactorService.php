<?php

namespace App\Services\Core;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Platform\PlatformAuditService;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\CauserResolver;

/**
 * TOTP second factor for the web login: enrolment, the login challenge, recovery codes and resets.
 *
 * Secret, recovery codes and the last accepted time-step live on `users` or `platform_admins` (encrypted casts)
 * and are written only here. A tenant user's events are audited under log name `security` with ids, a platform
 * admin's under `platform`, never secret material. A pending enrolment (secret without confirmed_at) enforces
 * nothing.
 *
 * The two planes stay apart: the parked login lives under its own session key, and the platform admin has no
 * remember token, no API token and no way to switch two-factor off (it is mandatory there).
 */
class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    /** No 0/o or 1/i/l: codes are read off paper. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Codes of one step on either side of the current one are accepted (clock drift between phone and server). */
    private const TOTP_WINDOW = 1;

    private const PENDING_LOGIN_KEY = 'login.two_factor';

    private const PENDING_PLATFORM_LOGIN_KEY = 'platform.login.two_factor';

    private const PENDING_LOGIN_MINUTES = 10;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly ApiTokenService $apiTokens,
        private readonly PlatformAuditService $platformAudit,
    ) {}

    /**
     * @return array{enabled: bool, pending: bool, recovery_codes_remaining: int}
     */
    public function status(User|PlatformAdmin $account): array
    {
        $enabled = $account->hasTwoFactorEnabled();

        return [
            'enabled' => $enabled,
            'pending' => ! $enabled && ($account->getAttributes()['two_factor_secret'] ?? null) !== null,
            'recovery_codes_remaining' => $enabled ? count($account->two_factor_recovery_codes ?? []) : 0,
        ];
    }

    /**
     * Starts, or restarts, enrolment with a fresh secret. Nothing is enforced until confirm() succeeds.
     *
     * @return array{setup_key: string, qr_svg: string}
     */
    public function beginEnrollment(User|PlatformAdmin $account): array
    {
        abort_if($account->hasTwoFactorEnabled(), 422, 'Autentikasi dua langkah sudah aktif.');

        $secret = $this->google2fa->generateSecretKey();

        $this->store($account, ['two_factor_secret' => $secret]);
        $this->audit('enrollment_started', $account);

        $issuer = config('app.name').($account instanceof PlatformAdmin ? ' Platform' : '');
        $uri = $this->google2fa->getQRCodeUrl($issuer, $account->email, $secret);

        return ['setup_key' => $secret, 'qr_svg' => $this->qrSvg($uri)];
    }

    /**
     * Activates the pending enrolment when $code is valid now and returns the recovery codes, which are
     * never retrievable again. Null when nothing is pending or the code is wrong. Turning it on ends the
     * account's other logins (see endOtherLogins); $session is the one making the request, when there is one.
     *
     * @return list<string>|null
     */
    public function confirm(User|PlatformAdmin $account, string $code, ?Session $session = null): ?array
    {
        $code = $this->normalizeCode($code);

        $codes = $this->withLockedAccount($account, function (User|PlatformAdmin $locked) use ($code): ?array {
            if ($locked->hasTwoFactorEnabled() || $locked->two_factor_secret === null) {
                return null;
            }

            $step = $this->matchingStep($locked, $code);

            if ($step === null) {
                return null;
            }

            $codes = $this->generateRecoveryCodes();
            // The confirming code counts as used so it cannot be replayed at the very next login.
            $this->store($locked, [
                'two_factor_recovery_codes' => $codes,
                'two_factor_confirmed_at' => now(),
                'two_factor_last_used_step' => $step,
            ]);

            return $codes;
        });

        if ($codes !== null) {
            $this->endOtherLogins($account, $session);
            $this->audit('enabled', $account);
        }

        return $codes;
    }

    /**
     * Whether $code, a TOTP code or a recovery code, completes the login challenge. A TOTP code is
     * accepted once; a recovery code is consumed.
     */
    public function verifyChallenge(User|PlatformAdmin $account, string $code): bool
    {
        $code = $this->normalizeCode($code);
        $method = preg_match('/^\d{6}$/', $code) === 1 ? 'totp' : 'recovery_code';

        $passed = $this->withLockedAccount($account, fn (User|PlatformAdmin $locked): bool => $locked->hasTwoFactorEnabled()
            && ($method === 'totp' ? $this->consumeTotp($locked, $code) : $this->consumeRecoveryCode($locked, $code)));

        if (! $passed) {
            $this->audit('challenge_failed', $account, ['method' => $method]);
        } elseif ($method === 'recovery_code') {
            $this->audit('recovery_code_used', $account, ['remaining' => count($account->two_factor_recovery_codes ?? [])]);
        } else {
            $this->audit('challenge_passed', $account, ['method' => $method]);
        }

        return $passed;
    }

    /**
     * Replaces every recovery code; the old ones stop working, and so do the account's other logins.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User|PlatformAdmin $account, ?Session $session = null): array
    {
        abort_unless($account->hasTwoFactorEnabled(), 422, 'Autentikasi dua langkah belum aktif.');

        $codes = $this->withLockedAccount($account, function (User|PlatformAdmin $locked): array {
            $codes = $this->generateRecoveryCodes();
            $this->store($locked, ['two_factor_recovery_codes' => $codes]);

            return $codes;
        });

        $this->endOtherLogins($account, $session);
        $this->audit('recovery_codes_regenerated', $account);

        return $codes;
    }

    /** Turns two-factor authentication off, or cancels a pending enrolment. Never for a platform admin: it is mandatory there. */
    public function disable(User|PlatformAdmin $account, ?Session $session = null): void
    {
        abort_if($account instanceof PlatformAdmin, 403, 'Autentikasi dua langkah wajib untuk admin platform.');

        $wasEnabled = $account->hasTwoFactorEnabled();

        $this->clear($account);

        if ($wasEnabled) {
            $this->endOtherLogins($account, $session);
        }

        $this->audit('disabled', $account);
    }

    /**
     * For a colleague who lost both phone and recovery codes. The caller has checked the actor's authority; the
     * actor is a tenant user or, from the platform panel, a platform admin (both numbers are ids of different tables,
     * so the audit row names which). A support session acts as a tenant user but is the platform admin's doing: the
     * row then names the admin, as the activity log's causer does.
     */
    public function adminReset(User|PlatformAdmin $actor, User $target): void
    {
        $wasEnabled = $target->hasTwoFactorEnabled();

        $this->clear($target);

        if ($wasEnabled) {
            $this->endOtherLogins($target);
        }

        $resolved = app(CauserResolver::class)->resolve($actor);
        $by = $resolved instanceof User || $resolved instanceof PlatformAdmin ? $resolved : $actor;

        $this->audit('admin_reset', $target, [
            'actor_id' => $by->id,
            'actor_type' => $by instanceof PlatformAdmin ? 'platform_admin' : 'user',
        ], $by);
    }

    /**
     * Parks a password-checked account until the second factor passes. Only the id and the remember
     * choice are kept, so nobody is authenticated in the meantime; the new session id guards
     * against fixation of that half-open state.
     */
    public function startChallenge(Session $session, User|PlatformAdmin $account, bool $remember): void
    {
        $session->regenerate();
        $session->put($this->pendingKey($account::class), [
            'id' => $account->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::PENDING_LOGIN_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The account waiting on the challenge, under the 'user' key, or null when there is none, it expired, the
     * account was deactivated meanwhile, or no longer has two-factor authentication on (the login then starts
     * over). $accountClass names the plane: User (the web login) or PlatformAdmin.
     *
     * @param  class-string<User>|class-string<PlatformAdmin>  $accountClass
     * @return array{user: User|PlatformAdmin, remember: bool}|null
     */
    public function pendingChallenge(Session $session, string $accountClass = User::class): ?array
    {
        $key = $this->pendingKey($accountClass);
        $pending = $session->get($key);
        $account = is_array($pending) && ($pending['expires_at'] ?? 0) >= now()->getTimestamp()
            ? $accountClass::query()->find($pending['id'] ?? null)
            : null;

        if ($account === null || ! $account->is_active || ! $account->hasTwoFactorEnabled()) {
            $this->abandonChallenge($session, $accountClass);

            return null;
        }

        return ['user' => $account, 'remember' => (bool) $pending['remember']];
    }

    /** @param  class-string<User>|class-string<PlatformAdmin>  $accountClass */
    public function abandonChallenge(Session $session, string $accountClass = User::class): void
    {
        $session->forget($this->pendingKey($accountClass));
    }

    /**
     * Ends every login of $account except the one making the request ($current, when there is one): somebody who
     * turns two-factor authentication on often suspects a takeover, and whoever already holds a session or a
     * "remember me" cookie must not keep a way in that skips the second factor.
     *
     * - A new remember token kills every "remember me" cookie.
     * - The acting session gets a new id and its old record is destroyed, so a stolen copy of its cookie is dead.
     * - With the database session driver, which the deployment uses, the user's other sessions are deleted.
     *   File and Redis sessions cannot be listed by user: there they last until they expire (SESSION_LIFETIME).
     * - Every mobile app token goes too: a token skips the login, so it would skip the second factor as well.
     *
     * A platform admin has no remember token or app token, and `sessions.user_id` cannot tell a platform admin
     * from a tenant user with the same number, so deleting by that number could end a stranger's session: only
     * the acting session is renewed. Its other sessions never pass the challenge of their own (platform.2fa).
     */
    private function endOtherLogins(User|PlatformAdmin $account, ?Session $current = null): void
    {
        if ($account instanceof PlatformAdmin) {
            $current?->regenerate(destroy: true);

            return;
        }

        $this->store($account, ['remember_token' => Str::random(60)]);
        $this->apiTokens->revokeAll($account, 'two_factor_changed');

        $current?->regenerate(destroy: true);

        if (config('session.driver') !== 'database') {
            return;
        }

        // The acting session is written when the request ends, so its new id is no row yet; the exclusion
        // only guards against a handler that already saved it.
        $currentId = $current?->getId();

        DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $account->getKey())
            ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
            ->delete();
    }

    private function consumeTotp(User|PlatformAdmin $account, string $code): bool
    {
        $step = $this->matchingStep($account, $code);

        if ($step === null) {
            return false;
        }

        $this->store($account, ['two_factor_last_used_step' => $step]);

        return true;
    }

    private function consumeRecoveryCode(User|PlatformAdmin $account, string $code): bool
    {
        $needle = $this->normalizeRecoveryCode($code);
        $remaining = [];
        $matched = false;

        // Every stored code is compared, with hash_equals, so the time taken does not say which one matched.
        foreach ($account->two_factor_recovery_codes ?? [] as $stored) {
            if (hash_equals($this->normalizeRecoveryCode($stored), $needle)) {
                $matched = true;
            } else {
                $remaining[] = $stored;
            }
        }

        if ($matched) {
            $this->store($account, ['two_factor_recovery_codes' => $remaining]);
        }

        return $matched;
    }

    /**
     * The time-step $code is valid for, when it is newer than the last accepted step (the replay guard).
     */
    private function matchingStep(User|PlatformAdmin $account, string $code): ?int
    {
        // verifyKeyNewer returns a bare true for a null last step, so 0 stands in for "none yet".
        $step = $this->google2fa->verifyKeyNewer(
            $account->two_factor_secret,
            $code,
            $account->two_factor_last_used_step ?? 0,
            self::TOTP_WINDOW,
            intdiv(now()->getTimestamp(), $this->google2fa->getKeyRegeneration()),
        );

        return is_int($step) ? $step : null;
    }

    /**
     * Runs $callback on the account's row under a lock, so two requests cannot both spend the same code,
     * and keeps the caller's model in step with what was written.
     *
     * @template T
     *
     * @param  Closure(User|PlatformAdmin): T  $callback
     * @return T
     */
    private function withLockedAccount(User|PlatformAdmin $account, Closure $callback): mixed
    {
        return DB::transaction(function () use ($account, $callback) {
            $locked = $account->newQuery()->lockForUpdate()->findOrFail($account->getKey());
            $result = $callback($locked);
            $account->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });
    }

    private function clear(User|PlatformAdmin $account): void
    {
        $this->store($account, [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ]);
    }

    /**
     * The two-factor columns are not mass assignable, and the activity log skips them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function store(User|PlatformAdmin $account, array $attributes): void
    {
        $account->forceFill($attributes)->save();
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $group = static function (): string {
            $max = strlen(self::RECOVERY_ALPHABET) - 1;

            return implode('', array_map(
                static fn (): string => self::RECOVERY_ALPHABET[random_int(0, $max)],
                range(1, 5),
            ));
        };

        return array_map(static fn (): string => $group().'-'.$group(), range(1, self::RECOVERY_CODE_COUNT));
    }

    /** Authenticator apps group the digits ("123 456") and phones paste odd spaces; none of it is part of the code. */
    private function normalizeCode(string $code): string
    {
        return preg_replace('/[\s\p{Z}]+/u', '', $code) ?? '';
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtolower(str_replace(['-', ' '], '', $code));
    }

    private function qrSvg(string $uri): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(256), new SvgImageBackEnd())))->writeString($uri);

        // Without the XML declaration: the markup is shown through an image data URI.
        return ltrim((string) preg_replace('/^<\?xml[^>]*\?>/', '', $svg));
    }

    /** @param  class-string<User>|class-string<PlatformAdmin>  $accountClass */
    private function pendingKey(string $accountClass): string
    {
        return is_a($accountClass, PlatformAdmin::class, true) ? self::PENDING_PLATFORM_LOGIN_KEY : self::PENDING_LOGIN_KEY;
    }

    /**
     * A tenant user's event is a `security` row about that user; a platform admin's is a `platform` row caused by
     * the admin (a login has no session yet, so the causer cannot be read from the guard). $causer names the one
     * who acted on a tenant user.
     *
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, User|PlatformAdmin $subject, array $properties = [], User|PlatformAdmin|null $causer = null): void
    {
        if ($subject instanceof PlatformAdmin) {
            $this->platformAudit->platform('two_factor.'.$event, ['platform_admin_id' => $subject->id, ...$properties], $subject, $subject);

            return;
        }

        AuditService::log('security', 'two_factor.'.$event, ['user_id' => $subject->id, ...$properties], $subject, causer: $causer);
    }
}
