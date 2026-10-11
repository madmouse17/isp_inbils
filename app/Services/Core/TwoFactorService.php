<?php

namespace App\Services\Core;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP second factor for the web login: enrolment, the login challenge, recovery codes and resets.
 *
 * Secret, recovery codes and the last accepted time-step live on `users` (encrypted casts) and are
 * written only here. Every event is audited under log name `security` with ids, never secret
 * material. A pending enrolment (secret without confirmed_at) enforces nothing.
 */
class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    /** No 0/o or 1/i/l: codes are read off paper. */
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Codes of one step on either side of the current one are accepted (clock drift between phone and server). */
    private const TOTP_WINDOW = 1;

    private const PENDING_LOGIN_KEY = 'login.two_factor';

    private const PENDING_LOGIN_MINUTES = 10;

    public function __construct(
        private readonly Google2FA $google2fa,
        private readonly ApiTokenService $apiTokens,
    ) {}

    /**
     * @return array{enabled: bool, pending: bool, recovery_codes_remaining: int}
     */
    public function status(User $user): array
    {
        $enabled = $user->hasTwoFactorEnabled();

        return [
            'enabled' => $enabled,
            'pending' => ! $enabled && ($user->getAttributes()['two_factor_secret'] ?? null) !== null,
            'recovery_codes_remaining' => $enabled ? count($user->two_factor_recovery_codes ?? []) : 0,
        ];
    }

    /**
     * Starts, or restarts, enrolment with a fresh secret. Nothing is enforced until confirm() succeeds.
     *
     * @return array{setup_key: string, qr_svg: string}
     */
    public function beginEnrollment(User $user): array
    {
        abort_if($user->hasTwoFactorEnabled(), 422, 'Two-factor authentication is already enabled.');

        $secret = $this->google2fa->generateSecretKey();

        $this->store($user, ['two_factor_secret' => $secret]);
        $this->audit('enrollment_started', $user);

        $uri = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);

        return ['setup_key' => $secret, 'qr_svg' => $this->qrSvg($uri)];
    }

    /**
     * Activates the pending enrolment when $code is valid now and returns the recovery codes, which are
     * never retrievable again. Null when nothing is pending or the code is wrong. Turning it on ends the
     * user's other logins (see endOtherLogins); $session is the one making the request, when there is one.
     *
     * @return list<string>|null
     */
    public function confirm(User $user, string $code, ?Session $session = null): ?array
    {
        $code = $this->normalizeCode($code);

        $codes = $this->withLockedUser($user, function (User $locked) use ($code): ?array {
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
            $this->endOtherLogins($user, $session);
            $this->audit('enabled', $user);
        }

        return $codes;
    }

    /**
     * Whether $code, a TOTP code or a recovery code, completes the login challenge. A TOTP code is
     * accepted once; a recovery code is consumed.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        $code = $this->normalizeCode($code);
        $method = preg_match('/^\d{6}$/', $code) === 1 ? 'totp' : 'recovery_code';

        $passed = $this->withLockedUser($user, fn (User $locked): bool => $locked->hasTwoFactorEnabled()
            && ($method === 'totp' ? $this->consumeTotp($locked, $code) : $this->consumeRecoveryCode($locked, $code)));

        if (! $passed) {
            $this->audit('challenge_failed', $user, ['method' => $method]);
        } elseif ($method === 'recovery_code') {
            $this->audit('recovery_code_used', $user, ['remaining' => count($user->two_factor_recovery_codes ?? [])]);
        }

        return $passed;
    }

    /**
     * Replaces every recovery code; the old ones stop working, and so do the user's other logins.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, ?Session $session = null): array
    {
        abort_unless($user->hasTwoFactorEnabled(), 422, 'Two-factor authentication is not enabled.');

        $codes = $this->withLockedUser($user, function (User $locked): array {
            $codes = $this->generateRecoveryCodes();
            $this->store($locked, ['two_factor_recovery_codes' => $codes]);

            return $codes;
        });

        $this->endOtherLogins($user, $session);
        $this->audit('recovery_codes_regenerated', $user);

        return $codes;
    }

    /** Turns two-factor authentication off, or cancels a pending enrolment. */
    public function disable(User $user, ?Session $session = null): void
    {
        $wasEnabled = $user->hasTwoFactorEnabled();

        $this->clear($user);

        if ($wasEnabled) {
            $this->endOtherLogins($user, $session);
        }

        $this->audit('disabled', $user);
    }

    /** For a colleague who lost both phone and recovery codes. The caller has checked the actor's authority. */
    public function adminReset(User $actor, User $target): void
    {
        $wasEnabled = $target->hasTwoFactorEnabled();

        $this->clear($target);

        if ($wasEnabled) {
            $this->endOtherLogins($target);
        }

        $this->audit('admin_reset', $target, ['actor_id' => $actor->id]);
    }

    /**
     * Parks a password-checked user until the second factor passes. Only the id and the remember
     * choice are kept, so nobody is authenticated in the meantime; the new session id guards
     * against fixation of that half-open state.
     */
    public function startChallenge(Session $session, User $user, bool $remember): void
    {
        $session->regenerate();
        $session->put(self::PENDING_LOGIN_KEY, [
            'id' => $user->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::PENDING_LOGIN_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The user waiting on the challenge, or null when there is none, it expired, the user was deactivated
     * meanwhile, or no longer has two-factor authentication on (the login then starts over).
     *
     * @return array{user: User, remember: bool}|null
     */
    public function pendingChallenge(Session $session): ?array
    {
        $pending = $session->get(self::PENDING_LOGIN_KEY);
        $user = is_array($pending) && ($pending['expires_at'] ?? 0) >= now()->getTimestamp()
            ? User::query()->find($pending['id'] ?? null)
            : null;

        if ($user === null || ! $user->is_active || ! $user->hasTwoFactorEnabled()) {
            $this->abandonChallenge($session);

            return null;
        }

        return ['user' => $user, 'remember' => (bool) $pending['remember']];
    }

    public function abandonChallenge(Session $session): void
    {
        $session->forget(self::PENDING_LOGIN_KEY);
    }

    /**
     * Ends every login of $user except the one making the request ($current, when there is one): somebody who
     * turns two-factor authentication on often suspects a takeover, and whoever already holds a session or a
     * "remember me" cookie must not keep a way in that skips the second factor.
     *
     * - A new remember token kills every "remember me" cookie.
     * - The acting session gets a new id and its old record is destroyed, so a stolen copy of its cookie is dead.
     * - With the database session driver, which the deployment uses, the user's other sessions are deleted.
     *   File and Redis sessions cannot be listed by user: there they last until they expire (SESSION_LIFETIME).
     * - Every mobile app token goes too: a token skips the login, so it would skip the second factor as well.
     */
    private function endOtherLogins(User $user, ?Session $current = null): void
    {
        $this->store($user, ['remember_token' => Str::random(60)]);
        $this->apiTokens->revokeAll($user, 'two_factor_changed');

        $current?->regenerate(destroy: true);

        if (config('session.driver') !== 'database') {
            return;
        }

        // The acting session is written when the request ends, so its new id is no row yet; the exclusion
        // only guards against a handler that already saved it.
        $currentId = $current?->getId();

        DB::connection(config('session.connection'))
            ->table(config('session.table'))
            ->where('user_id', $user->getKey())
            ->when($currentId !== null, fn ($query) => $query->where('id', '!=', $currentId))
            ->delete();
    }

    private function consumeTotp(User $user, string $code): bool
    {
        $step = $this->matchingStep($user, $code);

        if ($step === null) {
            return false;
        }

        $this->store($user, ['two_factor_last_used_step' => $step]);

        return true;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $needle = $this->normalizeRecoveryCode($code);
        $remaining = [];
        $matched = false;

        // Every stored code is compared, with hash_equals, so the time taken does not say which one matched.
        foreach ($user->two_factor_recovery_codes ?? [] as $stored) {
            if (hash_equals($this->normalizeRecoveryCode($stored), $needle)) {
                $matched = true;
            } else {
                $remaining[] = $stored;
            }
        }

        if ($matched) {
            $this->store($user, ['two_factor_recovery_codes' => $remaining]);
        }

        return $matched;
    }

    /**
     * The time-step $code is valid for, when it is newer than the last accepted step (the replay guard).
     */
    private function matchingStep(User $user, string $code): ?int
    {
        // verifyKeyNewer returns a bare true for a null last step, so 0 stands in for "none yet".
        $step = $this->google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $code,
            $user->two_factor_last_used_step ?? 0,
            self::TOTP_WINDOW,
            intdiv(now()->getTimestamp(), $this->google2fa->getKeyRegeneration()),
        );

        return is_int($step) ? $step : null;
    }

    /**
     * Runs $callback on the user's row under a lock, so two requests cannot both spend the same code,
     * and keeps the caller's model in step with what was written.
     *
     * @template T
     *
     * @param  Closure(User): T  $callback
     * @return T
     */
    private function withLockedUser(User $user, Closure $callback): mixed
    {
        return DB::transaction(function () use ($user, $callback) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $result = $callback($locked);
            $user->setRawAttributes($locked->getAttributes(), true);

            return $result;
        });
    }

    private function clear(User $user): void
    {
        $this->store($user, [
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
    private function store(User $user, array $attributes): void
    {
        $user->forceFill($attributes)->save();
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

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, User $subject, array $properties = []): void
    {
        AuditService::log('security', 'two_factor.'.$event, ['user_id' => $subject->id, ...$properties], $subject);
    }
}
