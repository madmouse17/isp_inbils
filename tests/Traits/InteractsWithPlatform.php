<?php

namespace Tests\Traits;

use App\Models\PlatformAdmin;
use App\Support\PlatformSession;
use Database\Factories\PlatformAdminFactory;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * Platform panel tests: admins (plain or with two-factor on) and a session the way the panel leaves one after a
 * complete login. Inertia pages of the panel do not exist as React files yet, so a test that asserts a page
 * component passes `false` as the second argument of `->component()`.
 */
trait InteractsWithPlatform
{
    protected function setUpInteractsWithPlatform(): void
    {
        $this->withoutVite();
    }

    /** @param  array<string, mixed>  $attributes */
    protected function platformAdmin(array $attributes = []): PlatformAdmin
    {
        return PlatformAdmin::factory()->create($attributes);
    }

    /**
     * An admin with two-factor authentication on, plus the secret and recovery codes the enrolment returned. The
     * confirming code is spent: a challenge in the same 30-second step needs `totp($secret, 1)`.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{admin: PlatformAdmin, secret: string, codes: list<string>}
     */
    protected function enrolledPlatformAdmin(array $attributes = []): array
    {
        $admin = $this->platformAdmin($attributes);

        return ['admin' => $admin, ...$this->enrollTwoFactor($admin)];
    }

    /**
     * Signs $admin in on the platform guard the way a browser holds it: the guard's id in the session, plus the
     * markers a complete login leaves (activity clock, second factor passed just now). The session is a real
     * one: actingAs() would also make the platform guard the default guard of the whole test, which no request
     * does, and the tenant routes would then see the admin as their user. A marker set to null here is absent.
     * Extra session values (a stale activity time, a password confirmation) go in $session.
     *
     * @param  array<string, mixed>  $session
     */
    protected function signInPlatformAdmin(PlatformAdmin $admin, array $session = []): static
    {
        // A new browser request resolves its guards from scratch; a test's one application instance would keep the user (or the logout) of the last.
        $this->app['auth']->forgetGuards();

        /** @var SessionGuard $guard */
        $guard = Auth::guard('platform');

        return $this->withSession([
            $guard->getName() => $admin->getKey(),
            PlatformSession::LAST_ACTIVITY => now()->getTimestamp(),
            PlatformSession::TWO_FACTOR_VERIFIED => now()->getTimestamp(),
            ...$session,
        ]);
    }

    /** The session value of a platform password confirmation made $secondsAgo seconds ago. @return array<string, int> */
    protected function platformPasswordConfirmed(int $secondsAgo = 0): array
    {
        return [PlatformSession::PASSWORD_CONFIRMED => now()->getTimestamp() - $secondsAgo];
    }

    protected function platformPassword(): string
    {
        return PlatformAdminFactory::PASSWORD;
    }

    /** @return array{email: string, password: string} */
    protected function platformCredentials(PlatformAdmin $admin, ?string $password = null): array
    {
        return ['email' => $admin->email, 'password' => $password ?? $this->platformPassword()];
    }
}
