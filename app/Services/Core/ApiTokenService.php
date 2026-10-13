<?php

namespace App\Services\Core;

use App\Exceptions\ApiException;
use App\Http\Middleware\EnsureCustomerAccess;
use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use App\Queries\Admin\MyWorkQuery;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Personal access tokens of the two mobile apps. A token belongs to one user and one app and carries exactly one
 * ability, the app's name; it lives 90 days (customer) or 30 (technician) from issue. This class decides who may
 * hold one (the same rule at login and on every request), issues and rotates them, holds the two-factor
 * challenge that stands between a password and a token, and revokes them. Every issue and every revocation is
 * audited under log name `security`, with ids and never the token.
 */
final class ApiTokenService
{
    public const CUSTOMER = 'customer';

    public const TECHNICIAN = 'technician';

    /** @var list<string> */
    public const APPS = [self::CUSTOMER, self::TECHNICIAN];

    private const CHALLENGE_SECONDS = 300;

    private const CHALLENGE_WRONG_CODES = 5;

    /**
     * The app the request's token was issued for.
     *
     * @throws ApiException app_not_allowed, for a token that carries neither app ability
     */
    public function appOf(User $user): string
    {
        foreach (self::APPS as $app) {
            if ($user->tokenCan($app)) {
                return $app;
            }
        }

        throw $this->notAllowed();
    }

    /**
     * Whether $user may use $app: the web rules of each surface, plus a verified e-mail and a company. A customer
     * is the web portal's rule (customer role and a linked Customer); a technician is the My work rule. Returns the
     * Customer for the customer app.
     *
     * @throws ApiException password_reset_required for a legacy login still flagged, app_not_allowed otherwise
     */
    public function ensureMayUse(User $user, string $app): ?Customer
    {
        if ($user->getAttribute('must_change_password')) {
            throw new ApiException(403, 'password_reset_required', 'The password must be reset before this account can sign in.');
        }

        $customer = $app === self::CUSTOMER ? EnsureCustomerAccess::customerFor($user) : null;

        $mayUse = $user->email_verified_at !== null
            && CompanyService::idFor($user) !== null
            && ($app === self::CUSTOMER ? $customer !== null : MyWorkQuery::isEligible($user));

        return $mayUse ? $customer : throw $this->notAllowed();
    }

    /**
     * The token of a completed login. Nobody is signed in before this: it is called after the password, or after
     * the second factor when there is one.
     *
     * @throws ApiException
     */
    public function issue(User $user, string $app, string $deviceName): NewAccessToken
    {
        $this->ensureMayUse($user, $app);

        $token = $this->mint($user, $app, $deviceName, 'login');

        // last_login_at is written by the listener of this event, as for a web login.
        event(new Login('sanctum', $user, false));

        return $token;
    }

    /**
     * Swaps $current for a new token with the same name and app and a full lifetime, as one transaction: a
     * failure leaves the old token working and no second one behind. The old row is locked first, so two
     * refreshes racing on one token cannot both succeed: the second finds it gone and is a 401.
     *
     * @throws AuthenticationException
     */
    public function rotate(User $user, PersonalAccessToken $current): NewAccessToken
    {
        return DB::transaction(function () use ($user, $current): NewAccessToken {
            if (PersonalAccessToken::query()->lockForUpdate()->find($current->getKey()) === null) {
                throw new AuthenticationException();
            }

            $token = $this->mint($user, $this->appOf($user), $current->name, 'refresh');
            $current->delete();

            return $token;
        });
    }

    public function revokeToken(User $user, PersonalAccessToken $token, string $reason): void
    {
        $token->delete();

        $this->auditRevocation($user, $reason, 1);
    }

    /** Called by the User model (password changed, deactivated, deleted) and when a second factor changes. */
    public function revokeAll(User $user, string $reason): void
    {
        $count = $user->tokens()->delete();

        if ($count > 0) {
            $this->auditRevocation($user, $reason, $count);
        }
    }

    /**
     * A suspended company takes the app tokens of the users it leaves with no company to act in. Users who
     * still have an active home or membership keep theirs. Called by the Company model on is_active true to false.
     */
    public function revokeForSuspendedCompany(Company $company): void
    {
        User::query()
            ->whereHas('tokens')
            ->where(fn ($users) => $users
                ->where('company_id', $company->id)
                ->orWhereHas('companies', fn ($companies) => $companies->whereKey($company->id)))
            ->chunkById(200, function ($users): void {
                foreach ($users as $user) {
                    if (! CompanyService::canEnter($user)) {
                        $this->revokeAll($user, 'company_suspended');
                    }
                }
            });
    }

    /**
     * Parks a password-checked login until the second factor passes. Only user, app and device name are kept, for
     * five minutes under the hash of a random id. The id is not a credential: a token follows a valid code only.
     *
     * @return array{two_factor_required: true, challenge_id: string, expires_in: int}
     */
    public function startChallenge(User $user, string $app, string $deviceName): array
    {
        $id = Str::random(64);

        Cache::put($this->challengeKey($id), [
            'user_id' => $user->getKey(),
            'app' => $app,
            'device_name' => $deviceName,
        ], self::CHALLENGE_SECONDS);

        return ['two_factor_required' => true, 'challenge_id' => $id, 'expires_in' => self::CHALLENGE_SECONDS];
    }

    /**
     * The login waiting on $id, unless it expired, was spent, the user was deactivated meanwhile or turned the
     * second factor off (the login then starts over).
     *
     * @return array{user: User, app: string, device_name: string}
     *
     * @throws ApiException challenge_expired
     */
    public function pendingChallenge(string $id): array
    {
        $pending = Cache::get($this->challengeKey($id));
        $user = is_array($pending) ? User::query()->find($pending['user_id']) : null;

        if ($user === null || ! $user->is_active || ! $user->hasTwoFactorEnabled()) {
            $this->closeChallenge($id);

            throw $this->challengeExpired();
        }

        return ['user' => $user, 'app' => $pending['app'], 'device_name' => $pending['device_name']];
    }

    /**
     * Five wrong codes end a challenge for good: after them even the right code opens nothing. Called after the
     * throttle, so the sixth attempt inside the throttle window is a 429 and one after it a dead challenge.
     *
     * @throws ApiException challenge_expired
     */
    public function ensureCodeAttemptsLeft(string $id): void
    {
        if (RateLimiter::tooManyAttempts($this->wrongCodesKey($id), self::CHALLENGE_WRONG_CODES)) {
            $this->closeChallenge($id);

            throw $this->challengeExpired();
        }
    }

    public function recordWrongCode(string $id): void
    {
        RateLimiter::hit($this->wrongCodesKey($id), self::CHALLENGE_SECONDS);
    }

    public function closeChallenge(string $id): void
    {
        Cache::forget($this->challengeKey($id));
        RateLimiter::clear($this->wrongCodesKey($id));
    }

    private function mint(User $user, string $app, string $deviceName, string $via): NewAccessToken
    {
        $token = $user->createToken($deviceName, [$app], now()->addDays((int) config("sanctum.app_token_days.{$app}")));

        AuditService::log('security', 'api.token_issued', [
            'user_id' => $user->id,
            'token_id' => $token->accessToken->getKey(),
            'app' => $app,
            'device_name' => $deviceName,
            'ip' => request()->ip(),
            'via' => $via,
        ], $user);

        return $token;
    }

    private function auditRevocation(User $user, string $reason, int $count): void
    {
        AuditService::log('security', 'api.tokens_revoked', ['user_id' => $user->id, 'reason' => $reason, 'count' => $count], $user);
    }

    private function challengeKey(string $id): string
    {
        return 'api.two_factor.challenge.'.hash('sha256', $id);
    }

    private function wrongCodesKey(string $id): string
    {
        return 'api.two_factor.wrong_codes.'.hash('sha256', $id);
    }

    private function notAllowed(): ApiException
    {
        return new ApiException(403, 'app_not_allowed', 'This account cannot use this app.');
    }

    private function challengeExpired(): ApiException
    {
        return new ApiException(422, 'challenge_expired', 'The sign-in expired. Sign in again.');
    }
}
