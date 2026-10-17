<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Database\Seeders\RolePermissionSeeder;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithPlatform;

/**
 * What the tenant management tests of the platform panel share: the seeded roles, one enrolled operator, a browser
 * session of that operator, and a tenant (root company, one subsidiary, three users) to act on. Inertia pages of the
 * panel are React files a later ticket adds, so a page assertion passes `false` to `component()`.
 *
 * `setUpTenantFixtures` runs on its own (Laravel calls `setUp<TraitName>` of every trait a test class uses).
 */
trait TenantFixtures
{
    use EnrollsTwoFactor;
    use InteractsWithPlatform;

    protected PlatformAdmin $operator;

    protected function setUpTenantFixtures(): void
    {
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->operator = $this->enrolledPlatformAdmin()['admin'];
    }

    /** A browser session of the operator, with a fresh platform password confirmation unless $confirmed is false. */
    protected function asOperator(bool $confirmed = true): static
    {
        return $this->signInPlatformAdmin($this->operator, $confirmed ? $this->platformPasswordConfirmed() : []);
    }

    /**
     * The next request is a tenant visitor's. A test shares one application instance across requests, and a platform
     * request leaves the platform guard as the default guard (and its session in the store); no real request does.
     */
    protected function asTenantVisitor(): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    /**
     * A tenant: a root company ($code) with one subsidiary ($code-C), an admin and a staff user homed in the root and
     * a manager homed in the subsidiary, each with the default membership of their home. Names and emails follow
     * the role and the company code ("Admin ALP", admin.alp@example.test).
     *
     * @return array{root: Company, child: Company, admin: User, staff: User, branch: User}
     */
    protected function buildTenant(string $code, string $name): array
    {
        $root = Company::factory()->create(['name' => $name, 'code' => $code]);
        $child = Company::factory()->create(['name' => $name.' Cabang', 'code' => $code.'-C']);
        $child->forceFill(['parent_company_id' => $root->id])->save();

        $tenant = [
            'root' => $root,
            'child' => $child,
            'admin' => $this->tenantUser($root, 'admin'),
            'staff' => $this->tenantUser($root, 'staff'),
            'branch' => $this->tenantUser($child, 'manager'),
        ];
        CompanyService::resetCache();

        return $tenant;
    }

    protected function tenantUser(Company $home, string $role): User
    {
        $email = $role.'.'.strtolower($home->code).'@example.test';

        for ($n = 2; User::query()->where('email', $email)->exists(); $n++) {
            $email = $role.'.'.strtolower($home->code).".$n@example.test";
        }

        $user = User::factory()->create([
            'name' => ucfirst($role).' '.$home->code,
            'email' => $email,
            'company_id' => $home->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->assignRole($role);
        app(CompanyMembershipService::class)->grant($user, $home, true);

        return $user;
    }
}
