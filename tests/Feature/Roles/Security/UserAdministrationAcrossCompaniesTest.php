<?php

namespace Tests\Feature\Roles\Security;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CompanyMembershipService;
use App\Services\Core\CompanyRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;
use Tests\Traits\EnrollsTwoFactor;

/**
 * Roles belong to a company (ADR-004), but a login reaches every company it belongs to. Whoever changes a login (its
 * secret, its status, its second factor, its existence) therefore answers for what the user holds in each of them:
 * H1 (a user administrator of one company took over the administrator of another) and M1 (a deactivation only looked at
 * the company the request acts in).
 */
class UserAdministrationAcrossCompaniesTest extends TestCase
{
    use BuildsCompanyGroup;
    use EnrollsTwoFactor;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildGroup();
    }

    /** A member homed in $home who also belongs to $other, with a role in each (null: a member without a role there). */
    private function member(Company $home, string $homeRole, Company $other, ?string $otherRole): User
    {
        $user = $this->groupUser($home, $homeRole);
        app(CompanyMembershipService::class)->grant($user, $other);

        return $otherRole === null ? $user : $this->grantRole($user, $otherRole, $other);
    }

    /** @return list<string> the names of the roles the user holds in that company */
    private function rolesIn(User $user, Company $company): array
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.company_id', $company->id)
            ->orderBy('roles.name')
            ->pluck('roles.name')
            ->all();
    }

    /** @return array<string, mixed> the form of the user page with these changes */
    private function form(User $user, array $changes = []): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'is_active' => true, 'roles' => ['Admin'], ...$changes];
    }

    // ---------------------------------------------------------------------------------------------
    // H1
    // ---------------------------------------------------------------------------------------------

    public function test_an_admin_cannot_take_over_a_user_who_holds_more_than_he_does_in_another_company_they_share(): void
    {
        // X administers the head, and is a plain Teknisi in A. Y is a Staf at home and the administrator of A.
        $x = $this->member($this->head, 'admin', $this->subA, 'technician');
        $y = $this->member($this->head, 'staff', $this->subA, 'admin');
        $this->enrollTwoFactor($y);
        $secret = $y->getAuthPassword();
        $this->actingAs($x);

        $this->get(route('admin.users.edit', $y))->assertForbidden();
        $this->put(route('admin.users.update', $y), ['name' => $y->name, 'email' => 'taken-over@group.test', 'password' => 'new-secret-123', 'roles' => ['Staf']])->assertForbidden();
        $this->post(route('admin.users.two-factor.reset', $y))->assertForbidden();
        $this->delete(route('admin.users.destroy', $y))->assertForbidden();

        $y->refresh();
        $this->assertSame($secret, $y->getAuthPassword());
        $this->assertNotSame('taken-over@group.test', $y->email);
        $this->assertTrue($y->hasTwoFactorEnabled());
        $this->assertSame(['Admin'], $this->rolesIn($y, $this->subA));
        $this->assertTrue(User::query()->whereKey($y->id)->exists());

        // The screen does not offer what the policy refuses; the roles of THIS company are still his to give.
        $this->get(route('admin.users.index', ['per_page' => 50]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('users.data', function ($rows) use ($y): bool {
                $row = collect($rows)->firstWhere('id', $y->public_id);

                return $row['can']['delete'] === false && $row['can']['reset_two_factor'] === false && $row['can']['assign_roles'] === true;
            }));
    }

    public function test_a_user_with_no_role_in_the_other_company_does_not_cover_its_administrator_either(): void
    {
        $x = $this->member($this->head, 'admin', $this->subA, null);
        $y = $this->member($this->head, 'staff', $this->subA, 'admin');

        $this->actingAs($x)->put(route('admin.users.update', $y), $this->form($y, ['roles' => ['Staf'], 'email' => 'taken-over@group.test']))->assertForbidden();

        $this->assertNotSame('taken-over@group.test', $y->fresh()->email);
    }

    public function test_a_subsidiary_admin_cannot_take_over_the_head_admin_who_is_homed_in_the_subsidiary(): void
    {
        // H administers the head but is homed in A as a Staf; S administers A and is only a Staf in the head.
        $h = $this->member($this->subA, 'staff', $this->head, 'admin');
        $s = $this->member($this->subA, 'admin', $this->head, 'staff');
        $this->actingAs($s);

        $this->put(route('admin.users.update', $h), ['name' => $h->name, 'email' => 'taken-over@group.test', 'password' => 'new-secret-123', 'roles' => ['Staf']])->assertForbidden();
        $this->post(route('admin.users.two-factor.reset', $h))->assertForbidden();
        $this->delete(route('admin.users.destroy', $h))->assertForbidden();

        $this->assertNotSame('taken-over@group.test', $h->fresh()->email);
        $this->assertTrue(User::query()->whereKey($h->id)->exists());
    }

    public function test_an_admin_who_covers_the_user_in_every_company_still_administers_him(): void
    {
        $x = $this->member($this->head, 'admin', $this->subA, 'technician');
        $plain = $this->member($this->head, 'staff', $this->subA, 'technician');
        $this->enrollTwoFactor($plain);
        $this->actingAs($x);

        $this->post(route('admin.users.two-factor.reset', $plain))->assertRedirect();
        $this->assertFalse($plain->fresh()->hasTwoFactorEnabled());

        $this->put(route('admin.users.update', $plain), $this->form($plain, ['name' => 'Renamed', 'roles' => ['Staf']]))->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed', $plain->fresh()->name);

        $this->delete(route('admin.users.destroy', $plain))->assertRedirect();
        $this->assertFalse(User::query()->whereKey($plain->id)->exists());
    }

    public function test_roles_of_this_company_are_still_given_to_a_member_who_administers_elsewhere(): void
    {
        // The roles are the company's own: what he holds in A changes nothing here, and nothing here changes what he holds in A.
        $x = $this->member($this->head, 'admin', $this->subA, 'technician');
        $y = $this->member($this->head, 'staff', $this->subA, 'admin');

        $this->actingAs($x)->put(route('admin.users.roles.update', $y), ['roles' => ['Staf', 'Manajer']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['Manajer', 'Staf'], $this->rolesIn($y, $this->head));
        $this->assertSame(['Admin'], $this->rolesIn($y, $this->subA));
    }

    // ---------------------------------------------------------------------------------------------
    // M1
    // ---------------------------------------------------------------------------------------------

    public function test_a_login_cannot_be_deactivated_while_it_is_the_last_administrator_of_another_company(): void
    {
        // The head has a second administrator; A has only X.
        $x = $this->member($this->head, 'admin', $this->subA, 'admin');
        $this->groupUser($this->head);
        $this->actingAs($x);

        $this->put(route('admin.users.update', $x), $this->form($x, ['is_active' => false]))
            ->assertSessionHasErrors(['roles' => CompanyRoleService::LAST_ADMINISTRATOR]);
        $this->assertTrue($x->fresh()->is_active);

        // With another active administrator in A the same save goes through, and takes the login out of both.
        $this->groupUser($this->subA);
        $this->put(route('admin.users.update', $x), $this->form($x, ['is_active' => false]))->assertRedirect(route('admin.users.index'));
        $this->assertFalse($x->fresh()->is_active);
    }

    public function test_deactivating_someone_else_checks_every_company_he_administers(): void
    {
        $x = $this->member($this->head, 'admin', $this->subA, 'admin');
        $y = $this->member($this->head, 'admin', $this->subA, 'admin');
        $this->actingAs($x);

        // X remains an administrator of both, so Y may go.
        $this->put(route('admin.users.update', $y), $this->form($y, ['is_active' => false]))->assertRedirect(route('admin.users.index'));
        $this->assertFalse($y->fresh()->is_active);
        $this->put(route('admin.users.update', $y), $this->form($y))->assertRedirect(route('admin.users.index'));
        $this->assertTrue($y->fresh()->is_active);

        // Without the deactivation the save is the usual one: no check of the other companies, nothing refused.
        $this->put(route('admin.users.update', $x), $this->form($x, ['name' => 'Renamed']))->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed', $x->fresh()->name);
    }

    public function test_the_deletion_of_a_user_checks_every_company_he_administers(): void
    {
        $x = $this->member($this->head, 'admin', $this->subA, 'admin');
        $this->groupUser($this->head);

        $this->actingAs($x)->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors(['user' => CompanyRoleService::LAST_ADMINISTRATOR]);

        $this->assertTrue(User::query()->whereKey($x->id)->exists());
    }
}
