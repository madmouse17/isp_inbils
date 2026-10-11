<?php

namespace Tests\Traits;

use App\Models\User;
use App\Services\Core\TwoFactorService;
use Database\Seeders\RolePermissionSeeder;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Turns two-factor authentication on for a user through the service, the way the Profile page does,
 * and computes authenticator codes from the Carbon clock so a test can freeze or move time.
 */
trait EnrollsTwoFactor
{
    /**
     * Enrols and confirms $user. The confirming code is spent, so a login in the same 30-second step
     * needs `totp($secret, 1)`.
     *
     * @return array{secret: string, codes: list<string>}
     */
    protected function enrollTwoFactor(User $user): array
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
     * The seeder grants `user.two_factor.reset` to admin; this keeps the tests independent of whether
     * the seeded database already has it. Idempotent.
     */
    protected function grantTwoFactorResetToAdmin(): void
    {
        Permission::findOrCreate('user.two_factor.reset', 'web');
        Role::findOrCreate('admin', 'web')->givePermissionTo('user.two_factor.reset');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function seedRolesAndTwoFactorReset(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->grantTwoFactorResetToAdmin();
    }
}
