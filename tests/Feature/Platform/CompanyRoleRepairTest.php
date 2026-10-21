<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Support\OptimisticLock;
use App\Support\PublicId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * The operator repairs the roles of one company of a tenant (ADR-004): the tenant page lists them per company, and
 * create, update and delete go through CompanyRoleService behind the platform step-up. Each write leaves a `platform`
 * and a `support` audit row about the company; a role of another tenant answers 404; the service guards (separation
 * of duties, last administrator, users moved, stale edit) apply to the operator too.
 */
class CompanyRoleRepairTest extends TestCase
{
    use RefreshDatabase;
    use TenantFixtures;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $alpha;

    /** @var array{root: Company, child: Company, admin: User, staff: User, branch: User} */
    private array $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->buildTenant('ALP', 'Alpha Net');
        $this->beta = $this->buildTenant('BET', 'Beta Link');
        $this->tenantUser($this->alpha['child'], 'admin');
    }

    /** A role of the company, found by its template key. */
    private function roleOf(Company $company, string $templateKey): Role
    {
        return $this->companyRoles($company)[$templateKey];
    }

    /** @return list<string> */
    private function permissionsOf(Role $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    private function payload(Company $target, string $name, array $permissions = ['customer.view']): array
    {
        return ['company_id' => $target->public_id, 'name' => $name, 'permissions' => $permissions];
    }

    private function editUrl(Company $tenant, Role $role): string
    {
        return route('platform.companies.roles.edit', [$tenant, $role->public_id]);
    }

    public function test_only_a_platform_admin_with_a_verified_second_factor_reaches_the_repair_pages(): void
    {
        $role = $this->roleOf($this->alpha['root'], 'staff');
        $urls = [route('platform.companies.roles.create', $this->alpha['root']), $this->editUrl($this->alpha['root'], $role)];

        // One phase after the other: a platform request leaves its session in the test's store, so the operator comes last.
        foreach ($urls as $url) {
            $this->asTenantVisitor()->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->actingAs($this->alpha['admin'])->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->signInPlatformAdmin($this->operator, ['platform.two_factor_verified_at' => null])->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->asOperator(false)->get($url)->assertOk();
        }
    }

    public function test_the_tenant_page_lists_the_roles_of_every_company_of_the_tenant_and_nobody_elses(): void
    {
        $custom = $this->companyRole($this->alpha['child'], 'Penyelia', ['customer.view', 'ticket.view']);
        $this->grantRole($this->alpha['staff'], $custom, $this->alpha['child']);

        $this->asOperator(false)->get(route('platform.companies.show', $this->alpha['root']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('companyRoles.0.company', ['id' => $this->alpha['root']->public_id, 'name' => 'Alpha Net', 'code' => 'ALP'])
                ->where('companyRoles.1.company.code', 'ALP-C')
                ->has('companyRoles', 2)
                ->where('companyRoles.0.roles', fn ($roles): bool => collect($roles)->pluck('template_key')->filter()->sort()->values()->all() === ['admin', 'customer', 'finance', 'manager', 'noc', 'staff', 'technician', 'warehouse'])
                ->where('companyRoles.1.roles', fn ($roles): bool => collect($roles)->contains(fn (array $role): bool => $role['id'] === $custom->public_id
                    && $role['name'] === 'Penyelia'
                    && $role['template_key'] === null
                    && $role['permissions_count'] === 2
                    && $role['users_count'] === 1))
                ->where('companyRoles.0.roles', fn ($roles): bool => collect($roles)->firstWhere('template_key', 'admin')['users_count'] === 1)
                ->where('roleTemplates', fn ($templates): bool => collect($templates)->pluck('key')->take(3)->all() === ['admin', 'manager', 'noc'])
                ->where('roleTemplates.0.name', 'Admin'));

        $betaRoleIds = Role::withoutCompany()->whereIn('company_id', [$this->beta['root']->id, $this->beta['child']->id])->pluck('public_id')->all();
        $listed = collect($this->asOperator(false)->get(route('platform.companies.show', $this->alpha['root']))->inertiaProps('companyRoles'))
            ->flatMap(fn (array $group): array => array_column($group['roles'], 'id'))->all();
        $this->assertSame([], array_intersect($listed, $betaRoleIds));
    }

    public function test_the_admins_and_user_rows_show_the_roles_of_the_home_company(): void
    {
        $this->grantRole($this->alpha['staff'], 'manager', $this->alpha['child']);

        $this->asOperator(false)->get(route('platform.companies.show', $this->alpha['root']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('admins', fn ($admins): bool => collect($admins)->pluck('name')->contains('Admin ALP'))
                ->where('users.data', fn ($rows): bool => collect($rows)->firstWhere('name', 'Staff ALP')['roles'] === ['Staf']));
    }

    public function test_the_create_page_names_the_tenant_the_company_and_the_template_to_start_from(): void
    {
        $root = $this->alpha['root'];

        $this->asOperator(false)->get(route('platform.companies.roles.create', ['company' => $root, 'target' => $this->alpha['child']->public_id, 'template' => 'noc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Companies/Roles/Create', false)
                ->where('tenant', ['id' => $root->public_id, 'name' => 'Alpha Net', 'code' => 'ALP'])
                ->where('company', ['id' => $this->alpha['child']->public_id, 'name' => 'Alpha Net Cabang', 'code' => 'ALP-C'])
                ->where('template.key', 'noc')
                ->where('template.name', 'NOC & Layanan Pelanggan')
                ->where('template.permissions', fn ($permissions): bool => in_array('ticket.assign', $permissions->all(), true))
                ->has('permissions.data'));

        $this->asOperator(false)->get(route('platform.companies.roles.create', $root))
            ->assertInertia(fn (Assert $page) => $page->where('company.code', 'ALP')->where('template', null));
    }

    public function test_the_create_page_refuses_a_company_of_another_tenant_and_an_unknown_template(): void
    {
        $this->asOperator(false)->get(route('platform.companies.roles.create', ['company' => $this->alpha['root'], 'target' => $this->beta['root']->public_id]))->assertNotFound();
        $this->asOperator(false)->get(route('platform.companies.roles.create', ['company' => $this->alpha['root'], 'target' => $this->beta['child']->public_id]))->assertNotFound();
        $this->asOperator(false)->get(route('platform.companies.roles.create', ['company' => $this->alpha['root'], 'template' => 'no_such_template']))->assertNotFound();
    }

    public function test_the_edit_page_carries_the_role_its_version_and_the_move_targets_of_the_same_company(): void
    {
        $role = $this->roleOf($this->alpha['child'], 'manager');

        $this->asOperator(false)->get($this->editUrl($this->alpha['root'], $role))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Companies/Roles/Edit', false)
                ->where('tenant.code', 'ALP')
                ->where('company.code', 'ALP-C')
                ->where('role.data.id', $role->public_id)
                ->where('role.data.name', $role->name)
                ->where('role.data.template_key', 'manager')
                ->where('role.data.permissions', $this->permissionsOf($role))
                ->where('role.data.users_count', 1)
                ->where('role.data.version', OptimisticLock::version($role))
                ->where('moveTargets', function ($targets) use ($role): bool {
                    $ids = collect($targets)->pluck('id')->all();
                    $ofChild = Role::withoutCompany()->where('company_id', $this->alpha['child']->id)->pluck('public_id')->all();

                    return ! in_array($role->public_id, $ids, true)
                        && count($ids) === count($ofChild) - 1
                        && array_diff($ids, $ofChild) === [];
                })
                ->has('permissions.data'));
    }

    public function test_a_role_of_another_tenant_answers_404_on_every_route(): void
    {
        $foreign = $this->roleOf($this->beta['root'], 'staff');
        $alpha = $this->alpha['root'];

        $this->asOperator()->get($this->editUrl($alpha, $foreign))->assertNotFound();
        $this->asOperator()->put(route('platform.companies.roles.update', [$alpha, $foreign->public_id]), [
            'name' => 'Diambil alih', 'permissions' => ['customer.view'], OptimisticLock::FIELD => OptimisticLock::version($foreign),
        ])->assertNotFound();
        $this->asOperator()->delete(route('platform.companies.roles.destroy', [$alpha, $foreign->public_id]))->assertNotFound();
        $this->asOperator()->get($this->editUrl($alpha, $this->roleOf($this->alpha['root'], 'staff')))->assertOk();
        $this->asOperator()->get(route('platform.companies.roles.edit', [$alpha, PublicId::new()]))->assertNotFound();
        $this->asOperator()->get(route('platform.companies.roles.edit', [$alpha, '1']))->assertNotFound();

        $this->assertSame('Staf', $foreign->fresh()->name);
        $this->assertSame(0, Activity::query()->whereIn('description', ['role.created', 'role.updated', 'role.deleted'])->count(), 'a refused write leaves no trace (the fixture\'s role.attached rows are not writes of a role)');
    }

    public function test_every_repair_write_needs_a_fresh_password_confirmation(): void
    {
        $role = $this->roleOf($this->alpha['root'], 'staff');
        $alpha = $this->alpha['root'];
        $writes = [
            fn () => $this->post(route('platform.companies.roles.store', $alpha), $this->payload($alpha, 'Rogue')),
            fn () => $this->put(route('platform.companies.roles.update', [$alpha, $role->public_id]), ['name' => 'Rogue', 'permissions' => [], OptimisticLock::FIELD => OptimisticLock::version($role)]),
            fn () => $this->delete(route('platform.companies.roles.destroy', [$alpha, $role->public_id]), ['move_to' => $this->roleOf($alpha, 'manager')->public_id]),
        ];

        foreach ([[], $this->platformPasswordConfirmed(301)] as $session) {
            foreach ($writes as $write) {
                $this->signInPlatformAdmin($this->operator, $session);
                $write()->assertRedirect(route('platform.password.confirm'));
            }
        }

        $this->assertFalse(Role::withoutCompany()->where('name', 'Rogue')->exists());
        $this->assertTrue(Role::withoutCompany()->whereKey($role->id)->exists());
        $this->assertSame(0, Activity::query()->whereIn('description', ['role.created', 'role.updated', 'role.deleted'])->count(), 'a refused write leaves no trace (the fixture\'s role.attached rows are not writes of a role)');
    }

    public function test_a_role_is_added_to_a_subsidiary_and_both_audit_rows_name_that_company(): void
    {
        $child = $this->alpha['child'];

        $this->asOperator()
            ->post(route('platform.companies.roles.store', $this->alpha['root']), $this->payload($child, 'Penyelia', ['ticket.view', 'customer.view']))
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']))
            ->assertSessionHas('success');

        $role = Role::withoutCompany()->where('company_id', $child->id)->where('name', 'Penyelia')->firstOrFail();
        $this->assertSame('web', $role->guard_name);
        $this->assertNull($role->template_key);
        $this->assertSame(['customer.view', 'ticket.view'], $this->permissionsOf($role));
        $this->assertFalse(Role::withoutCompany()->where('company_id', $this->alpha['root']->id)->where('name', 'Penyelia')->exists(), 'only the chosen company');

        foreach (['platform', 'support'] as $log) {
            $row = Activity::query()->where('log_name', $log)->where('description', 'role.created')->sole();
            $this->assertSame(PlatformAdmin::class, $row->causer_type);
            $this->assertSame($this->operator->id, (int) $row->causer_id);
            $this->assertSame($child->id, (int) $row->company_id);
            $this->assertSame('Penyelia', $row->properties['name']);
            $this->assertSame(['customer.view', 'ticket.view'], collect($row->properties['permissions'])->sort()->values()->all());
        }
    }

    public function test_the_company_must_belong_to_the_tenant_and_the_name_is_unique_inside_it_only(): void
    {
        $alpha = $this->alpha['root'];
        $taken = $this->roleOf($alpha, 'staff')->name;
        $cases = [
            'a company of another tenant' => [$this->payload($this->beta['root'], 'Penyelia'), 'company_id'],
            'a subsidiary of another tenant' => [$this->payload($this->beta['child'], 'Penyelia'), 'company_id'],
            'no company' => [['name' => 'Penyelia'], 'company_id'],
            'an integer id' => [['company_id' => $alpha->id, 'name' => 'Penyelia'], 'company_id'],
            'no name' => [$this->payload($alpha, ''), 'name'],
            'too long' => [$this->payload($alpha, str_repeat('x', 256)), 'name'],
            'a name taken in that company' => [$this->payload($alpha, $taken), 'name'],
            'unknown permission' => [$this->payload($alpha, 'Penyelia', ['no.such.permission']), 'permissions.0'],
            'a permission twice' => [$this->payload($alpha, 'Penyelia', ['customer.view', 'customer.view']), 'permissions.0'],
            'permissions that are no list' => [[...$this->payload($alpha, 'Penyelia'), 'permissions' => 'customer.view'], 'permissions'],
        ];

        foreach ($cases as $label => [$payload, $field]) {
            $this->asOperator()->post(route('platform.companies.roles.store', $alpha), $payload)->assertSessionHasErrors($field);
            $this->assertFalse(Role::withoutCompany()->where('name', 'Penyelia')->exists(), $label);
        }

        $this->asOperator()->post(route('platform.companies.roles.store', $alpha), $this->payload($this->alpha['child'], $taken))->assertSessionHasErrors('name');

        $this->asOperator()->post(route('platform.companies.roles.store', $alpha), $this->payload($alpha, 'Penyelia'))->assertRedirect();
        $this->asOperator()->post(route('platform.companies.roles.store', $alpha), $this->payload($this->alpha['child'], 'Penyelia'))->assertRedirect();
        $this->assertSame(2, Role::withoutCompany()->where('name', 'Penyelia')->count(), 'the same name may exist in two companies');
    }

    public function test_a_role_is_renamed_and_its_permissions_changed_with_the_diff_in_both_audit_rows(): void
    {
        $role = $this->companyRole($this->alpha['child'], 'Penyelia', ['customer.view']);

        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$this->alpha['root'], $role->public_id]), [
                'name' => 'Penyelia Cabang',
                'permissions' => ['customer.view', 'ticket.view'],
                OptimisticLock::FIELD => OptimisticLock::version($role),
            ])
            ->assertRedirect(route('platform.companies.show', $this->alpha['root']));

        $role->refresh();
        $this->assertSame('Penyelia Cabang', $role->name);
        $this->assertSame(['customer.view', 'ticket.view'], $this->permissionsOf($role));

        foreach (['platform', 'support'] as $log) {
            $row = Activity::query()->where('log_name', $log)->where('description', 'role.updated')->sole();
            $this->assertSame($this->alpha['child']->id, (int) $row->company_id);
            $this->assertSame('Penyelia', $row->properties['name_before']);
            $this->assertSame('Penyelia Cabang', $row->properties['name_after']);
            $this->assertSame(['ticket.view'], $row->properties['permissions_added']);
            $this->assertSame([], $row->properties['permissions_removed']);
        }
    }

    public function test_a_stale_edit_is_refused_with_a_409(): void
    {
        $role = $this->companyRole($this->alpha['child'], 'Penyelia', ['customer.view']);
        $version = OptimisticLock::version($role);
        $this->travel(2)->seconds();
        $role->forceFill(['name' => 'Penyelia Baru'])->save();

        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$this->alpha['root'], $role->public_id]), [
                'name' => 'Penyelia Lama', 'permissions' => ['customer.view'], OptimisticLock::FIELD => $version,
            ])
            ->assertStatus(409);

        $this->assertSame('Penyelia Baru', $role->fresh()->name);
        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$this->alpha['root'], $role->public_id]), ['name' => 'X', 'permissions' => []])
            ->assertSessionHasErrors(OptimisticLock::FIELD);
    }

    public function test_the_separation_of_duties_applies_to_the_operator_unless_the_set_holds_roles_manage(): void
    {
        $alpha = $this->alpha['root'];
        $approve = 'Pemisahan tugas (persetujuan permintaan barang vs pelaksanaan gudang): inventory.request.approve tidak boleh digabung dengan inventory.request.prepare.';

        $this->asOperator()
            ->post(route('platform.companies.roles.store', $alpha), $this->payload($alpha, 'Gudang Super', ['inventory.request.approve', 'inventory.request.prepare']))
            ->assertSessionHasErrors(['permissions' => $approve]);

        $ops = $this->companyRole($alpha, 'Gudang Ops', ['inventory.request.prepare']);
        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$alpha, $ops->public_id]), [
                'name' => 'Gudang Ops', 'permissions' => ['inventory.request.prepare', 'inventory.request.approve'], OptimisticLock::FIELD => OptimisticLock::version($ops),
            ])
            ->assertSessionHasErrors(['permissions' => $approve]);
        $this->assertSame(['inventory.request.prepare'], $this->permissionsOf($ops->fresh()));
        $this->assertFalse(Role::withoutCompany()->where('name', 'Gudang Super')->exists());

        $this->asOperator()
            ->post(route('platform.companies.roles.store', $alpha), $this->payload($alpha, 'Pemilik', ['roles.manage', 'app.web', 'inventory.request.approve', 'inventory.request.prepare']))
            ->assertRedirect();
        $this->assertTrue(Role::withoutCompany()->where('company_id', $alpha->id)->where('name', 'Pemilik')->exists());
    }

    public function test_a_role_with_users_needs_a_move_target_of_the_same_company_and_moves_them(): void
    {
        $alpha = $this->alpha['root'];
        $role = $this->roleOf($alpha, 'staff');
        $target = $this->roleOf($alpha, 'noc');
        $foreignTarget = $this->roleOf($this->alpha['child'], 'noc');
        $url = route('platform.companies.roles.destroy', [$alpha, $role->public_id]);

        $this->asOperator()->delete($url)->assertSessionHasErrors('move_to');
        $this->asOperator()->delete($url, ['move_to' => $role->public_id])->assertSessionHasErrors('move_to');
        $this->asOperator()->delete($url, ['move_to' => $foreignTarget->public_id])->assertSessionHasErrors('move_to');
        $this->asOperator()->delete($url, ['move_to' => $this->roleOf($this->beta['root'], 'noc')->public_id])->assertSessionHasErrors('move_to');
        $this->assertTrue(Role::withoutCompany()->whereKey($role->id)->exists());

        $this->asOperator()->delete($url, ['move_to' => $target->public_id])->assertRedirect(route('platform.companies.show', $alpha));

        $this->assertFalse(Role::withoutCompany()->whereKey($role->id)->exists());
        $this->assertTrue(DB::table('model_has_roles')->where(['role_id' => $target->id, 'model_id' => $this->alpha['staff']->id])->exists(), 'the user took over the target role');
        foreach (['platform', 'support'] as $log) {
            $row = Activity::query()->where('log_name', $log)->where('description', 'role.deleted')->sole();
            $this->assertSame($alpha->id, (int) $row->company_id);
            $this->assertSame($role->name, $row->properties['name']);
            $this->assertArrayHasKey('moved_users', $row->properties->toArray());
            $this->assertArrayHasKey('moved_to', $row->properties->toArray());
        }
    }

    public function test_a_role_without_users_is_deleted_without_a_target_and_the_customer_role_never(): void
    {
        $alpha = $this->alpha['root'];
        $idle = $this->companyRole($alpha, 'Penyelia', ['customer.view']);

        $this->asOperator()->delete(route('platform.companies.roles.destroy', [$alpha, $idle->public_id]))->assertRedirect(route('platform.companies.show', $alpha));
        $this->assertFalse(Role::withoutCompany()->whereKey($idle->id)->exists());

        $customer = $this->roleOf($alpha, 'customer');
        $this->asOperator()->delete(route('platform.companies.roles.destroy', [$alpha, $customer->public_id]))->assertSessionHasErrors(['role' => 'Peran ini dipakai untuk akun pelanggan baru.']);
        $this->assertTrue(Role::withoutCompany()->whereKey($customer->id)->exists());
    }

    public function test_moving_users_cannot_stack_a_separation_of_duties_conflict(): void
    {
        $alpha = $this->alpha['root'];
        $approver = $this->companyRole($alpha, 'Penyetuju', ['inventory.request.approve']);
        $preparer = $this->companyRole($alpha, 'Penyiap', ['inventory.request.prepare']);
        $spare = $this->companyRole($alpha, 'Cadangan', ['customer.view']);
        $this->grantRole($this->alpha['staff'], $preparer, $alpha);
        $this->grantRole($this->alpha['staff'], $spare, $alpha);

        $this->asOperator()
            ->delete(route('platform.companies.roles.destroy', [$alpha, $spare->public_id]), ['move_to' => $approver->public_id])
            ->assertSessionHasErrors();

        $this->assertTrue(Role::withoutCompany()->whereKey($spare->id)->exists());
    }

    public function test_the_last_administrator_cannot_be_removed_by_a_repair(): void
    {
        $alpha = $this->alpha['root'];
        $admin = $this->roleOf($alpha, 'admin');
        // roles.manage stays in the set, so the separation-of-duties check is exempt and the last-administrator guard decides.
        $permissions = array_values(array_diff($this->permissionsOf($admin), ['app.web']));
        $message = 'Perusahaan harus tetap punya minimal satu admin aktif yang memegang roles.manage.';

        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$alpha, $admin->public_id]), ['name' => $admin->name, 'permissions' => $permissions, OptimisticLock::FIELD => OptimisticLock::version($admin)])
            ->assertSessionHasErrors(['roles' => $message]);
        $this->assertContains('roles.manage', $this->permissionsOf($admin->fresh()));

        $this->asOperator()
            ->delete(route('platform.companies.roles.destroy', [$alpha, $admin->public_id]), ['move_to' => $this->roleOf($alpha, 'staff')->public_id])
            ->assertSessionHasErrors(['roles' => $message]);
        $this->assertTrue(Role::withoutCompany()->whereKey($admin->id)->exists());
        $this->assertSame(0, Activity::query()->whereIn('description', ['role.updated', 'role.deleted'])->count());
    }

    public function test_a_company_with_no_administrator_can_be_repaired(): void
    {
        $alpha = $this->alpha['root'];
        $admin = $this->roleOf($alpha, 'admin');
        DB::table('model_has_roles')->where('role_id', $admin->id)->delete();
        $role = $this->roleOf($alpha, 'staff');

        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$alpha, $role->public_id]), ['name' => 'Staf Baru', 'permissions' => ['dashboard.view'], OptimisticLock::FIELD => OptimisticLock::version($role)])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('platform.companies.show', $alpha));

        $this->assertSame('Staf Baru', $role->fresh()->name);
    }

    public function test_a_repair_in_one_company_leaves_the_same_named_role_of_another_company_alone(): void
    {
        $child = $this->alpha['child'];
        $alphaStaff = $this->roleOf($this->alpha['root'], 'staff');
        $childStaff = $this->roleOf($child, 'staff');
        $before = $this->permissionsOf($alphaStaff);

        $this->asOperator()
            ->put(route('platform.companies.roles.update', [$this->alpha['root'], $childStaff->public_id]), [
                'name' => 'Staf Cabang', 'permissions' => ['dashboard.view'], OptimisticLock::FIELD => OptimisticLock::version($childStaff),
            ])
            ->assertRedirect();

        $this->assertSame('Staf Cabang', $childStaff->fresh()->name);
        $this->assertSame(['dashboard.view'], $this->permissionsOf($childStaff->fresh()));
        $this->assertSame($alphaStaff->name, $alphaStaff->fresh()->name);
        $this->assertSame($before, $this->permissionsOf($alphaStaff->fresh()));
    }
}
