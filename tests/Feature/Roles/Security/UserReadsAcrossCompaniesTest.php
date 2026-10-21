<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Permission;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * What the user policy reads about a user in every company he belongs to (H1) comes from the user himself, loaded once:
 * `rolesInEveryCompany` and `companies`. These pin that the answers are the ones the package and the table give, and that
 * an instance does not keep answering from what it loaded before the roles or the memberships changed.
 */
class UserReadsAcrossCompaniesTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildGroup();
    }

    /** A user homed in the head who is a member of both subsidiaries, with a different role in each and a direct permission. */
    private function traveller(): User
    {
        $user = $this->groupUser($this->head, 'manager');
        foreach ([[$this->subA, 'staff'], [$this->subB, 'technician']] as [$company, $role]) {
            app(CompanyMembershipService::class)->grant($user, $company);
            $this->grantRole($user, $role, $company);
        }
        $this->grantRole($user, $this->companyRole($this->head, 'Kasir', ['billing.view', 'billing.payment.record']), $this->head);
        $user->givePermissionTo(Permission::findOrCreate('report.sla.view', 'web'));

        return User::query()->findOrFail($user->id);
    }

    /** @return list<string> sorted, de-duplicated */
    private function namesOf(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function test_the_permissions_in_each_company_are_the_ones_the_package_gives_when_acting_as_that_company(): void
    {
        $user = $this->traveller();

        foreach ([$this->head, $this->subA, $this->subB, $this->outsider] as $company) {
            $asThatCompany = CompanyService::runAs($company->id, fn (): array => $this->namesOf(User::query()->findOrFail($user->id)->getAllPermissions()->pluck('name')->all()));

            $this->assertSame($asThatCompany, $this->namesOf($user->permissionNamesIn($company->id)), "company {$company->code}");
        }

        // They differ from company to company, and the direct permission applies everywhere.
        $this->assertContains('billing.payment.record', $user->permissionNamesIn($this->head->id));
        $this->assertNotContains('billing.payment.record', $user->permissionNamesIn($this->subB->id));
        $this->assertSame(['report.sla.view'], $user->permissionNamesIn($this->outsider->id), 'where he holds no role he holds what is his own');
    }

    public function test_one_load_serves_every_company_and_a_change_of_company_does_not_reload_it(): void
    {
        $user = $this->traveller();
        $user->permissionNamesIn($this->head->id);

        // The package's `roles` follows the company in effect: it is dropped and read again for each of these.
        foreach ([$this->subA, $this->subB, $this->head, $this->subA] as $company) {
            CompanyService::runAs($company->id, fn () => $user->roles->count());
        }
        $this->assertTrue($user->relationLoaded('rolesInEveryCompany'), 'a company in effect that changes does not drop what is held in every company');

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            foreach ([$this->head, $this->subA, $this->subB, $this->outsider, $this->subA] as $company) {
                $user->permissionNamesIn($company->id);
            }
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertSame([], $queries, 'what he holds in a company is read from what was loaded once');
    }

    public function test_a_role_given_or_taken_on_the_instance_is_seen_at_once(): void
    {
        $user = $this->traveller();
        $this->assertNotContains('report.technician.team', $user->permissionNamesIn($this->subB->id));

        $this->grantRole($user, 'manager', $this->subB);
        $this->assertContains('report.technician.team', $user->permissionNamesIn($this->subB->id), 'the role given in subB shows');
        $this->assertNotContains('report.technician.team', $user->permissionNamesIn($this->subA->id));

        CompanyService::runAs($this->subB->id, fn () => $user->syncRoles([]));
        $this->assertSame(['report.sla.view'], $user->permissionNamesIn($this->subB->id), 'the roles taken away in subB are gone');
        $this->assertNotSame(['report.sla.view'], $user->permissionNamesIn($this->head->id), 'his roles in the head are not');
    }

    public function test_the_company_ids_are_the_home_company_and_the_grants_and_follow_a_grant_and_a_revoke(): void
    {
        $memberships = app(CompanyMembershipService::class);
        $user = $this->groupUser($this->head, 'staff');

        $this->assertEqualsCanonicalizing([$this->head->id], $user->companyIds()->all());
        $this->assertEqualsCanonicalizing($memberships->companyIdsOf($user)->all(), $user->companyIds()->all());

        $memberships->grant($user, $this->subA);
        $this->assertEqualsCanonicalizing([$this->head->id, $this->subA->id], $user->companyIds()->all(), 'a grant shows on the instance that was granted');
        $this->assertEqualsCanonicalizing($memberships->companyIdsOf($user)->all(), $user->companyIds()->all());

        $memberships->revoke($user, $this->subA);
        $this->assertEqualsCanonicalizing([$this->head->id], $user->companyIds()->all(), 'a revoke too');
    }

    public function test_the_home_company_counts_without_a_membership_row(): void
    {
        $user = User::factory()->create(['company_id' => $this->subB->id]);

        $this->assertSame([], DB::table('company_user')->where('user_id', $user->id)->pluck('company_id')->all());
        $this->assertSame([$this->subB->id], $user->companyIds()->all());
        $this->assertSame([$this->subB->id], app(CompanyMembershipService::class)->companyIdsOf($user)->all());

        $nobody = User::factory()->create(['company_id' => null]);
        $this->assertSame([], $nobody->companyIds()->all());
    }

    public function test_the_membership_of_a_company_that_is_not_active_still_counts(): void
    {
        // Whoever answers for a login answers for every company it belongs to, a suspended one included.
        $this->subB->forceFill(['is_active' => false])->save();
        $guest = $this->groupUser($this->head, 'staff');
        app(CompanyMembershipService::class)->grant($guest, $this->subB);
        $actor = $this->groupUser($this->head, 'admin');

        $this->assertContains($this->subB->id, User::query()->findOrFail($guest->id)->companyIds()->all());
        $this->assertFalse(app(CompanyMembershipService::class)->administers($actor, User::query()->findOrFail($guest->id)));
    }

    public function test_the_actors_roles_in_every_company_are_dropped_with_his_roles_when_they_change(): void
    {
        $actor = $this->groupUser($this->head, 'admin');
        app(CompanyMembershipService::class)->grant($actor, $this->subA);
        $this->grantRole($actor, 'staff', $this->subA);
        $this->assertNotContains('users.manage', $actor->permissionNamesIn($this->subA->id));

        $this->grantRole($actor, 'admin', $this->subA);

        $this->assertContains('users.manage', $actor->permissionNamesIn($this->subA->id));
    }
}
