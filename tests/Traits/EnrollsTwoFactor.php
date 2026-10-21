<?php

namespace Tests\Traits;

use App\Models\Permission;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\TwoFactorService;
use PragmaRX\Google2FA\Google2FA;

/**
 * Turns two-factor authentication on for a user or a platform admin through the service, the way the Profile page
 * does, and computes authenticator codes from the Carbon clock so a test can freeze or move time.
 */
trait EnrollsTwoFactor
{
    /**
     * Enrols and confirms $user. The confirming code is spent, so a login in the same 30-second step
     * needs `totp($secret, 1)`.
     *
     * @return array{secret: string, codes: list<string>}
     */
    protected function enrollTwoFactor(User|PlatformAdmin $user): array
    {
        $service = app(TwoFactorService::class);
        $secret = $service->beginEnrollment($user)['setup_key'];
        $codes = $service->confirm($user, $this->totp($secret));

        $this->assertNotNull($codes, 'The test secret should confirm with the current code.');

        return ['secret' => $secret, 'codes' => $codes];
    }

    /** The authenticator code for the current time-step plus $stepOffset. */
    protected function totp(string $secret, int $stepOffset = 0): string
    {
        return app(Google2FA::class)->oathTotp($secret, intdiv(now()->getTimestamp(), 30) + $stepOffset);
    }

    /**
     * The Admin template holds `users.two_factor.reset`, so a company's Admin copy already has it; this only makes sure
     * the row exists. Kept for the callers that still ask: the call can go.
     */
    protected function grantTwoFactorResetToAdmin(): void
    {
        Permission::findOrCreate('users.two_factor.reset', 'web');
    }
}
