<?php

namespace Tests\Feature\Tenant;

use App\Models\Core\Company;
use App\Models\User;
use App\Services\Core\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\BuildsCompanyGroup;

/**
 * Roles are global (Q5) and a membership lets one login act in several companies, so two admin
 * surfaces become cross-company the moment a group exists: the role definitions and the user
 * accounts. Both may only be changed by someone who answers for every company the change reaches.
 */
class CompanyAdminAuthorityTest extends TestCase
{
    use BuildsCompanyGroup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->buildGroup();
    }

    // ---------------------------------------------------------------------------------------------
    // Role definitions belong to the top-level company
    // ---------------------------------------------------------------------------------------------

    public function test_a_subsidiary_admin_cannot_create_change_or_delete_role_definitions(): void
    {
        $custom = Role::create(['name' => 'support-lead', 'guard_name' => 'web'])->syncPermissions(['customer.view']);
        $adminRole = Role::findByName('admin');
        $adminPermissions = $adminRole->permissions()->count();
        $subAdmin = $this->groupUser($this->subA);

        $this->actingAs($subAdmin);
        $this->get(route('admin.roles.create'))->assertForbidden();
        $this->get(route('admin.roles.edit', $custom))->assertForbidden();
        $this->post(route('admin.roles.store'), ['name' => 'rogue', 'permissions' => ['customer.view']])->assertForbidden();
        $this->put(route('admin.roles.update', $custom), ['name' => 'support-lead', 'permissions' => ['customer.view', 'billing.view', 'billing.export']])->assertForbidden();
        $this->put(route('admin.roles.update', $adminRole), ['name' => 'admin', 'permissions' => ['dashboard.view']])->assertForbidden();
        $this->delete(route('admin.roles.destroy', $custom))->assertForbidden();

        $this->assertFalse(Role::query()->where('name', 'rogue')->exists());
        $this->assertSame(['customer.view'], $custom->fresh()->permissions->pluck('name')->all());
        $this->assertSame($adminPermissions, $adminRole->fresh()->permissions()->count());
    }

    public function test_a_head_admin_and_a_standalone_company_admin_still_manage_roles(): void
    {
        foreach ([$this->head, $this->outsider] as $company) {
            $admin = $this->groupUser($company);

            $this->actingAs($admin)
                ->post(route('admin.roles.store'), ['name' => 'role-of-'.$company->code, 'permissions' => ['customer.view']])
                ->assertRedirect(route('admin.roles.index'));
            $role = Role::findByName('role-of-'.$company->code);

            $this->put(route('admin.roles.update', $role), ['name' => 'role-of-'.$company->code, 'permissions' => ['customer.view', 'ticket.view']])
                ->assertRedirect(route('admin.roles.index'));
            $this->assertEqualsCanonicalizing(['customer.view', 'ticket.view'], $role->fresh()->permissions->pluck('name')->all());

            $this->delete(route('admin.roles.destroy', $role))->assertRedirect();
            $this->assertFalse(Role::query()->where('name', $role->name)->exists());
        }
    }

    public function test_switching_into_a_subsidiary_takes_role_management_away_and_switching_back_returns_it(): void
    {
        $user = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($user)->post(route('admin.company.switch'), ['company_id' => $this->subA->id]);
        $this->post(route('admin.roles.store'), ['name' => 'while-in-sub', 'permissions' => []])->assertForbidden();

        $this->post(route('admin.company.switch'), ['company_id' => $this->head->id]);
        $this->post(route('admin.roles.store'), ['name' => 'while-in-head', 'permissions' => []])->assertRedirect(route('admin.roles.index'));
        $this->assertTrue(Role::query()->where('name', 'while-in-head')->exists());
        $this->assertFalse(Role::query()->where('name', 'while-in-sub')->exists());
    }

    public function test_the_role_list_counts_only_the_users_of_the_effective_company_and_hides_the_create_button_from_subsidiaries(): void
    {
        Role::create(['name' => 'support-lead', 'guard_name' => 'web']);
        $this->groupUser($this->head, 'support-lead');
        $this->groupUser($this->subA, 'support-lead');
        $this->groupUser($this->subA, 'support-lead');
        $headAdmin = $this->groupUser($this->head);
        $subAdmin = $this->groupUser($this->subA);
        $countOf = fn (array $roles): ?int => collect($roles)->firstWhere('name', 'support-lead')['users_count'] ?? null;

        $this->actingAs($headAdmin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', true)
                ->where('roles.data', fn ($roles): bool => $countOf(collect($roles)->all()) === 1));

        $this->actingAs($subAdmin)->get(route('admin.roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.create', false)
                ->where('roles.data', fn ($roles): bool => $countOf(collect($roles)->all()) === 2));
    }

    // ---------------------------------------------------------------------------------------------
    // User accounts: the actor must answer for every company the target can act in
    // ---------------------------------------------------------------------------------------------

    public function test_a_head_admin_cannot_take_over_a_head_user_who_also_belongs_to_a_subsidiary_the_admin_is_not_in(): void
    {
        $shared = $this->groupUser($this->head, 'staff', $this->subA);
        $headAdmin = $this->groupUser($this->head);
        $passwordHash = $shared->password;

        $this->actingAs($headAdmin);
        $this->put(route('admin.users.update', $shared), [
            'name' => 'Taken over',
            'email' => 'takeover@group.test',
            'password' => 'new-password-123',
            'roles' => ['admin'],
        ])->assertForbidden();
        $this->get(route('admin.users.edit', $shared))->assertForbidden();
        $this->get(route('admin.users.show', $shared))->assertForbidden();
        $this->delete(route('admin.users.destroy', $shared))->assertForbidden();

        $shared->refresh();
        $this->assertSame($passwordHash, $shared->password);
        $this->assertNotSame('takeover@group.test', $shared->email);
        $this->assertSame(['staff'], $shared->getRoleNames()->all());
        $this->assertTrue(User::query()->whereKey($shared->id)->exists());
    }

    public function test_a_subsidiary_admin_cannot_take_over_a_subsidiary_user_who_also_belongs_to_the_head(): void
    {
        $shared = $this->groupUser($this->subA, 'staff', $this->head);
        $subAdmin = $this->groupUser($this->subA);
        $passwordHash = $shared->password;

        $this->actingAs($subAdmin);
        $this->put(route('admin.users.update', $shared), [
            'name' => 'Taken over',
            'email' => 'takeover@group.test',
            'password' => 'new-password-123',
            'roles' => ['admin'],
        ])->assertForbidden();
        $this->delete(route('admin.users.destroy', $shared))->assertForbidden();

        $shared->refresh();
        $this->assertSame($passwordHash, $shared->password);
        $this->assertSame(['staff'], $shared->getRoleNames()->all());
    }

    public function test_an_actor_who_holds_the_same_memberships_may_administer_the_user_and_so_may_the_user_themself(): void
    {
        $shared = $this->groupUser($this->head, 'staff', $this->subA);
        $plain = $this->groupUser($this->head, 'staff');
        $both = $this->groupUser($this->head, 'admin', $this->subA);

        $this->actingAs($both);
        $this->put(route('admin.users.update', $shared), ['name' => 'Renamed', 'email' => $shared->email, 'roles' => ['staff']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('Renamed', $shared->fresh()->name);
        $this->put(route('admin.users.update', $both), ['name' => $both->name, 'email' => $both->email, 'roles' => ['admin']])
            ->assertRedirect(route('admin.users.index'));
        $this->delete(route('admin.users.destroy', $shared))->assertRedirect();
        $this->assertFalse(User::query()->whereKey($shared->id)->exists());

        // A user with only the home membership is administered exactly as before.
        $headAdmin = $this->groupUser($this->head);
        $this->actingAs($headAdmin)
            ->put(route('admin.users.update', $plain), ['name' => 'Plain renamed', 'email' => $plain->email, 'roles' => ['staff']])
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('Plain renamed', $plain->fresh()->name);
    }

    // ---------------------------------------------------------------------------------------------
    // Smaller invariants
    // ---------------------------------------------------------------------------------------------

    public function test_a_head_needs_at_least_one_active_subsidiary(): void
    {
        $lonely = Company::factory()->create(['name' => 'Lonely Head', 'code' => 'LNH']);
        $dormant = Company::factory()->create(['code' => 'LNC', 'is_active' => false]);
        $dormant->forceFill(['parent_company_id' => $lonely->id])->save();
        $admin = $this->groupUser($lonely);

        $this->assertFalse($lonely->isHead());
        $this->actingAs($admin)->get(route('admin.reports.consolidated'))->assertForbidden();
        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('companyHierarchy.can_consolidate', false));

        $dormant->forceFill(['is_active' => true])->save();

        $this->assertTrue($lonely->isHead());
    }

    public function test_a_customer_portal_user_gets_its_default_membership(): void
    {
        $this->actingAs($this->groupUser($this->subA));

        $customer = CustomerService::createWithUser([
            'code' => 'PORTAL-1',
            'name' => 'Portal Customer',
            'type' => 'Individual',
            'email' => 'portal@group.test',
            'phone' => '081234567890',
            'is_active' => true,
        ]);

        $user = User::query()->findOrFail($customer->user_id);
        $this->assertSame($this->subA->id, $user->company_id);
        $this->assertSame(
            [[$this->subA->id, 1]],
            DB::table('company_user')->where('user_id', $user->id)->get()
                ->map(fn (object $row): array => [(int) $row->company_id, (int) $row->is_default])->all(),
        );
    }
}
