<?php

namespace Tests\Feature\Platform;

use App\Models\Core\Company;
use App\Models\PlatformAdmin;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\EnrollsTwoFactor;
use Tests\Traits\InteractsWithPlatform;

/**
 * Role definitions are global (spatie teams=false), so their CRUD lives in the platform panel (ADR-003): every
 * write needs a fresh platform password confirmation, always lands on the `web` guard, keeps the five seeded
 * names, and is audited with the permission lists before and after. The Inertia pages are React files a later
 * ticket adds, so the assertions pass `false` to `component()`.
 */
class PlatformRoleManagementTest extends TestCase
{
    use EnrollsTwoFactor;
    use InteractsWithPlatform;
    use RefreshDatabase;

    private const SEEDED = ['admin', 'manager', 'staff', 'technician', 'customer'];

    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $this->admin = $this->enrolledPlatformAdmin()['admin'];
    }

    /** A browser session of the platform admin, with a fresh password confirmation unless $confirmed is false. */
    private function asPlatformAdmin(bool $confirmed = true): static
    {
        return $this->signInPlatformAdmin($this->admin, $confirmed ? $this->platformPasswordConfirmed() : []);
    }

    /** @return list<string> */
    private function permissionsOf(string $role): array
    {
        return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
    }

    private function customRole(string $name = 'support-lead', string ...$permissions): Role
    {
        return Role::create(['name' => $name, 'guard_name' => 'web'])->syncPermissions($permissions);
    }

    public function test_only_a_platform_admin_with_a_verified_second_factor_reaches_the_role_pages(): void
    {
        $role = $this->customRole();
        $urls = [route('platform.roles.index'), route('platform.roles.create'), route('platform.roles.edit', $role)];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('platform.login'));
        }

        $tenantAdmin = User::factory()->create(['company_id' => Company::factory()->create()->id, 'email_verified_at' => now()]);
        $tenantAdmin->assignRole('admin');
        foreach ($urls as $url) {
            $this->actingAs($tenantAdmin)->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->signInPlatformAdmin($this->admin, ['platform.two_factor_verified_at' => null])->get($url)->assertRedirect(route('platform.login'));
        }

        foreach ($urls as $url) {
            $this->asPlatformAdmin(false)->get($url)->assertOk();
        }
    }

    public function test_the_index_lists_the_web_roles_with_their_global_user_counts_and_the_protected_names(): void
    {
        $this->customRole('support-lead', 'customer.view');
        foreach ([Company::factory()->create(), Company::factory()->create()] as $company) {
            User::factory()->create(['company_id' => $company->id])->assignRole('support-lead');
        }
        Role::create(['name' => 'other-guard-role', 'guard_name' => 'api']);

        $this->asPlatformAdmin(false)->get(route('platform.roles.index', ['search' => 'support']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Roles/Index')
                ->where('protectedRoles', self::SEEDED)
                ->where('filters', ['search' => 'support'])
                ->has('roles.data', 1, fn (Assert $row) => $row
                    ->where('name', 'support-lead')
                    ->where('permissions', ['customer.view'])
                    ->where('users_count', 2)
                    ->etc()));

        $this->asPlatformAdmin(false)->get(route('platform.roles.index', ['per_page' => 50]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles.data', fn ($roles): bool => collect($roles)->pluck('name')->sort()->values()->all()
                    === collect([...self::SEEDED, 'support-lead'])->sort()->values()->all()));
    }

    public function test_the_create_page_lists_every_web_permission(): void
    {
        $first = Permission::query()->where('guard_name', 'web')->orderBy('name')->firstOrFail();

        $this->asPlatformAdmin(false)->get(route('platform.roles.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Roles/Create')
                ->has('permissions.data', Permission::query()->where('guard_name', 'web')->count())
                ->where('permissions.data.0.name', $first->name)
                ->where('permissions.data.0.group', Str::before($first->name, '.')));
    }

    public function test_the_edit_page_warns_about_a_seeded_role_and_not_about_a_custom_one(): void
    {
        $custom = $this->customRole('support-lead', 'customer.view');

        $this->asPlatformAdmin(false)->get(route('platform.roles.edit', Role::findByName('manager')))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Platform/Roles/Edit')
                ->where('role.data.name', 'manager')
                ->where('role.data.permissions', fn ($names): bool => collect($names)->sort()->values()->all() === $this->permissionsOf('manager'))
                ->where('protectedRoles', self::SEEDED)
                ->where('seededWarning', fn ($warning): bool => is_string($warning) && str_contains($warning, 'RolePermissionSeeder'))
                ->has('permissions.data'));

        $this->asPlatformAdmin(false)->get(route('platform.roles.edit', $custom))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('role.data.name', 'support-lead')
                ->where('role.data.permissions', ['customer.view'])
                ->where('seededWarning', null));
    }

    public function test_every_role_write_needs_a_fresh_password_confirmation(): void
    {
        $custom = $this->customRole('support-lead', 'customer.view');
        $writes = [
            fn () => $this->post(route('platform.roles.store'), ['name' => 'rogue', 'permissions' => ['customer.view']]),
            fn () => $this->put(route('platform.roles.update', $custom), ['name' => 'support-lead', 'permissions' => ['customer.view', 'ticket.view']]),
            fn () => $this->delete(route('platform.roles.destroy', $custom)),
        ];

        foreach ([[], $this->platformPasswordConfirmed(301)] as $session) {
            foreach ($writes as $write) {
                $this->signInPlatformAdmin($this->admin, $session);
                $write()->assertRedirect(route('platform.password.confirm'));
            }
        }

        $this->assertFalse(Role::query()->where('name', 'rogue')->exists());
        $this->assertSame(['customer.view'], $this->permissionsOf('support-lead'));
        $this->assertSame(0, Activity::query()->where('description', 'like', 'role.%')->count());
    }

    public function test_a_platform_admin_creates_a_global_web_role_and_the_write_is_audited(): void
    {
        $this->asPlatformAdmin()
            ->post(route('platform.roles.store'), ['name' => 'support-lead', 'permissions' => ['ticket.view', 'customer.view']])
            ->assertRedirect(route('platform.roles.index'));

        $role = Role::findByName('support-lead', 'web');
        $this->assertSame('web', $role->guard_name);
        $this->assertSame(['customer.view', 'ticket.view'], $this->permissionsOf('support-lead'));

        $row = Activity::query()->where('description', 'role.created')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertSame(PlatformAdmin::class, $row->causer_type);
        $this->assertSame($this->admin->id, (int) $row->causer_id);
        $this->assertSame(Role::class, $row->subject_type);
        $this->assertSame($role->id, (int) $row->subject_id);
        $this->assertNull($row->company_id);
        $this->assertEquals([
            'name_before' => null,
            'name_after' => 'support-lead',
            'permissions_before' => [],
            'permissions_after' => ['customer.view', 'ticket.view'],
        ], $row->properties->toArray());
    }

    public function test_a_role_without_permissions_can_be_created(): void
    {
        $this->asPlatformAdmin()->post(route('platform.roles.store'), ['name' => 'empty-role'])->assertRedirect(route('platform.roles.index'));

        $this->assertSame([], $this->permissionsOf('empty-role'));
    }

    public function test_the_role_name_and_permissions_are_validated(): void
    {
        $this->customRole('support-lead');
        $cases = [
            'missing name' => [['permissions' => []], 'name'],
            'too long' => [['name' => str_repeat('x', 256)], 'name'],
            'taken on the web guard' => [['name' => 'support-lead'], 'name'],
            'seeded name' => [['name' => 'manager'], 'name'],
            'unknown permission' => [['name' => 'fresh', 'permissions' => ['no.such.permission']], 'permissions.0'],
            'not a list' => [['name' => 'fresh', 'permissions' => 'customer.view'], 'permissions'],
            'permission of another guard' => [['name' => 'fresh', 'permissions' => ['api.only']], 'permissions.0'],
        ];
        Permission::create(['name' => 'api.only', 'guard_name' => 'api']);

        foreach ($cases as $label => [$payload, $field]) {
            $this->asPlatformAdmin()->post(route('platform.roles.store'), $payload)->assertSessionHasErrors($field);
            $this->assertFalse(Role::query()->where('name', 'fresh')->exists(), $label);
        }
    }

    public function test_the_same_name_may_exist_on_another_guard(): void
    {
        Role::create(['name' => 'shared-name', 'guard_name' => 'api']);

        $this->asPlatformAdmin()->post(route('platform.roles.store'), ['name' => 'shared-name'])->assertRedirect(route('platform.roles.index'));

        $this->assertSame(1, Role::query()->where('name', 'shared-name')->where('guard_name', 'web')->count());
    }

    public function test_a_custom_role_can_be_renamed_and_its_permissions_changed_with_an_audit_of_both(): void
    {
        $custom = $this->customRole('support-lead', 'customer.view');
        User::factory()->create(['company_id' => Company::factory()->create()->id])->assignRole('support-lead');

        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', $custom), ['name' => 'support-desk', 'permissions' => ['ticket.view', 'customer.view']])
            ->assertRedirect(route('platform.roles.index'));
        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', $custom), ['name' => 'support-desk', 'permissions' => ['ticket.view']])
            ->assertRedirect(route('platform.roles.index'));

        $this->assertSame('support-desk', $custom->fresh()->name);
        $this->assertSame(['ticket.view'], $this->permissionsOf('support-desk'));

        $rows = Activity::query()->where('description', 'role.updated')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertEquals([
            'name_before' => 'support-lead',
            'name_after' => 'support-desk',
            'permissions_before' => ['customer.view'],
            'permissions_after' => ['customer.view', 'ticket.view'],
        ], $rows[0]->properties->toArray());
        $this->assertSame(['customer.view', 'ticket.view'], $rows[1]->properties['permissions_before']);
        $this->assertSame(['ticket.view'], $rows[1]->properties['permissions_after']);
        $this->assertSame('platform', $rows[1]->log_name);
        $this->assertSame($this->admin->id, (int) $rows[1]->causer_id);
    }

    public function test_a_role_name_cannot_take_the_name_of_another_role(): void
    {
        $custom = $this->customRole('support-lead');
        $this->customRole('billing-desk');

        $this->asPlatformAdmin()->put(route('platform.roles.update', $custom), ['name' => 'billing-desk'])->assertSessionHasErrors('name');
        $this->asPlatformAdmin()->put(route('platform.roles.update', $custom), ['name' => 'manager'])->assertSessionHasErrors('name');

        $this->assertSame('support-lead', $custom->fresh()->name);
    }

    public function test_seeded_role_names_are_immutable_but_their_permissions_are_editable(): void
    {
        foreach (self::SEEDED as $name) {
            $role = Role::findByName($name, 'web');

            $this->asPlatformAdmin()
                ->put(route('platform.roles.update', $role), ['name' => $name.'-renamed', 'permissions' => ['dashboard.view']])
                ->assertSessionHasErrors(['name' => 'Nama peran bawaan tidak dapat diubah.']);
            $this->assertSame($name, $role->fresh()->name);
            $this->assertNotSame(['dashboard.view'], $this->permissionsOf($name));
        }

        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', Role::findByName('staff', 'web')), ['name' => 'staff', 'permissions' => ['dashboard.view', 'customer.view']])
            ->assertRedirect(route('platform.roles.index'));

        $this->assertSame(['customer.view', 'dashboard.view'], $this->permissionsOf('staff'));
        $row = Activity::query()->where('description', 'role.updated')->sole();
        $this->assertSame('staff', $row->properties['name_after']);
        $this->assertGreaterThan(2, count($row->properties['permissions_before']));
        $this->assertSame(['customer.view', 'dashboard.view'], $row->properties['permissions_after']);
    }

    public function test_seeded_roles_cannot_be_deleted_and_a_custom_role_can(): void
    {
        foreach (self::SEEDED as $name) {
            $this->asPlatformAdmin()->delete(route('platform.roles.destroy', Role::findByName($name, 'web')))->assertForbidden();
            $this->assertTrue(Role::query()->where('name', $name)->exists(), $name);
        }
        $this->assertSame(0, Activity::query()->where('description', 'role.deleted')->count());

        $custom = $this->customRole('support-lead', 'customer.view');
        $holder = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $holder->assignRole('support-lead');

        $this->asPlatformAdmin()->delete(route('platform.roles.destroy', $custom))->assertRedirect(route('platform.roles.index'));

        $this->assertFalse(Role::query()->where('name', 'support-lead')->exists());
        $this->assertFalse($holder->fresh()->hasRole('admin'));
        $this->assertSame([], $holder->fresh()->getRoleNames()->all());

        $row = Activity::query()->where('description', 'role.deleted')->sole();
        $this->assertSame('platform', $row->log_name);
        $this->assertEquals([
            'name_before' => 'support-lead',
            'name_after' => null,
            'permissions_before' => ['customer.view'],
            'permissions_after' => [],
        ], $row->properties->toArray());
    }

    public function test_a_role_that_breaks_the_separation_of_duties_is_refused_unless_it_is_the_admin_role(): void
    {
        $approve = 'Pemisahan tugas (persetujuan permintaan barang vs pelaksanaan gudang): inventory.request.approve tidak boleh digabung dengan inventory.request.prepare.';
        $breakGlass = 'Hanya administrator yang boleh memberikan hak akses darurat inventory.stock.adjust.';

        $this->asPlatformAdmin()
            ->post(route('platform.roles.store'), ['name' => 'warehouse_super', 'permissions' => ['inventory.request.approve', 'inventory.request.prepare']])
            ->assertSessionHasErrors(['permissions' => $approve]);
        $this->asPlatformAdmin()
            ->post(route('platform.roles.store'), ['name' => 'break_glass_clone', 'permissions' => ['inventory.stock.adjust']])
            ->assertSessionHasErrors(['permissions' => $breakGlass]);
        $this->assertSame(0, Role::query()->whereIn('name', ['warehouse_super', 'break_glass_clone'])->count());

        $this->asPlatformAdmin()
            ->post(route('platform.roles.store'), ['name' => 'warehouse_ops', 'permissions' => ['inventory.request.prepare', 'inventory.request.issue']])
            ->assertRedirect(route('platform.roles.index'));
        $ops = Role::findByName('warehouse_ops', 'web');

        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', $ops), ['name' => 'warehouse_ops', 'permissions' => ['inventory.request.prepare', 'inventory.request.approve']])
            ->assertSessionHasErrors(['permissions' => $approve]);
        $this->assertSame(['inventory.request.issue', 'inventory.request.prepare'], $this->permissionsOf('warehouse_ops'));

        $manager = Role::findByName('manager', 'web');
        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', $manager), ['name' => 'manager', 'permissions' => [...$this->permissionsOf('manager'), 'inventory.request.prepare']])
            ->assertSessionHasErrors(['permissions' => 'Pemisahan tugas (persetujuan permintaan barang vs pelaksanaan gudang): inventory.request.approve, inventory.request.reject tidak boleh digabung dengan inventory.request.prepare.']);
        $this->assertNotContains('inventory.request.prepare', $this->permissionsOf('manager'));

        $adminKeeps = array_values(array_diff($this->permissionsOf('admin'), ['dashboard.view']));
        $this->assertContains('inventory.stock.adjust', $adminKeeps);
        $this->assertContains('inventory.request.approve', $adminKeeps);
        $this->assertContains('inventory.request.prepare', $adminKeeps);

        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', Role::findByName('admin', 'web')), ['name' => 'admin', 'permissions' => $adminKeeps])
            ->assertRedirect(route('platform.roles.index'));
        $this->assertSame($adminKeeps, $this->permissionsOf('admin'));
    }

    public function test_a_malformed_permission_list_is_a_validation_error_not_a_crash(): void
    {
        $this->asPlatformAdmin()
            ->post(route('platform.roles.store'), ['name' => 'malformed_role', 'permissions' => [['inventory.request.approve'], 'inventory.request.prepare']])
            ->assertSessionHasErrors('permissions.0');

        $this->assertFalse(Role::query()->where('name', 'malformed_role')->exists());
    }

    public function test_a_role_change_reaches_the_users_of_every_tenant_at_once(): void
    {
        $custom = $this->customRole('support-lead');
        $users = collect([Company::factory()->create(), Company::factory()->create()])
            ->map(fn (Company $company): User => User::factory()->create(['company_id' => $company->id]));
        $users->each->assignRole('support-lead');
        $users->each(fn (User $user) => $this->assertFalse($user->can('customer.view')));

        $this->asPlatformAdmin()
            ->put(route('platform.roles.update', $custom), ['name' => 'support-lead', 'permissions' => ['customer.view']])
            ->assertRedirect(route('platform.roles.index'));

        $users->each(fn (User $user) => $this->assertTrue($user->fresh()->can('customer.view')));
    }
}
