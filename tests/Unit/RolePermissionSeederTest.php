<?php

namespace Tests\Unit;

use App\Models\Core\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleTemplate;
use App\Models\User;
use App\Support\PermissionCatalogue;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The seeder installs the permission catalogue and the default role templates (ADR-004) and nothing else: it creates
 * no role, and it never changes an existing template or a company's roles. Each test starts from a database that has
 * neither the permissions nor the templates, the state of a fresh install.
 */
class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATES = ['admin', 'manager', 'noc', 'technician', 'warehouse', 'finance', 'staff', 'customer'];

    protected function setUp(): void
    {
        parent::setUp();

        // The migrations installed both; the cascades take the template grants with them.
        DB::table('role_templates')->delete();
        DB::table('permissions')->delete();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_installs_every_catalogue_permission_once(): void
    {
        $this->assertEqualsCanonicalizing(PermissionCatalogue::ALL, Permission::query()->pluck('name')->all());
        $this->assertSame(count(PermissionCatalogue::ALL), Permission::query()->count());
    }

    public function test_creates_at_least_80_permissions(): void
    {
        $this->assertGreaterThanOrEqual(80, Permission::query()->count());
    }

    public function test_installs_the_eight_default_templates_and_no_role(): void
    {
        $this->assertSame(self::TEMPLATES, RoleTemplate::query()->orderBy('id')->pluck('key')->all());
        $this->assertSame(0, Role::withoutCompany()->count());
    }

    public function test_no_users_seeded(): void
    {
        $this->assertSame(0, User::query()->count());
    }

    public function test_admin_template_has_every_permission_except_those_that_classify_a_person(): void
    {
        $this->assertEqualsCanonicalizing(
            Permission::query()->whereNotIn('name', PermissionCatalogue::UNGUARDED)->pluck('name')->all(),
            $this->permissionsOf('admin'),
        );
    }

    public function test_portal_invoice_permissions_belong_to_the_customer_template_only(): void
    {
        foreach (self::TEMPLATES as $key) {
            foreach (['portal.invoice.view', 'portal.invoice.pay'] as $permission) {
                $this->assertSame($key === 'customer', in_array($permission, $this->permissionsOf($key), true), "$key $permission");
            }
        }
    }

    public function test_two_factor_reset_permission_is_admin_only(): void
    {
        $this->assertSame(['admin'], $this->holdersOf('users.two_factor.reset'));
    }

    public function test_admin_has_customer_manage_permission(): void
    {
        $this->assertContains('customer.manage', $this->permissionsOf('admin'));
    }

    public function test_inventory_stock_permissions_are_installed_and_mapped(): void
    {
        foreach (['inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer', 'inventory.stock.adjust', 'inventory.stock.adjust.request', 'inventory.stock.adjust.approve'] as $permission) {
            $this->assertTrue(Permission::query()->where('name', $permission)->exists());
            $this->assertContains($permission, $this->permissionsOf('admin'));
        }

        // SoD: Manager is controller, Staff is operator.
        $manager = $this->permissionsOf('manager');
        $staff = $this->permissionsOf('staff');

        $this->assertContains('inventory.stock.adjust.approve', $manager);

        foreach (['inventory.stock.adjust', 'inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer'] as $permission) {
            $this->assertNotContains($permission, $manager);
        }

        foreach (['inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer', 'inventory.stock.adjust.request'] as $permission) {
            $this->assertContains($permission, $staff);
        }

        $this->assertNotContains('inventory.stock.adjust.approve', $staff);
        $this->assertContains('inventory.stock.issue', $this->permissionsOf('technician'));
    }

    public function test_report_permissions_are_installed(): void
    {
        foreach ([
            'report.view', 'report.business.view', 'report.sales.view', 'report.asset.view', 'report.sla.view',
            'report.stock_card.view', 'report.dictionary.view', 'report.technician', 'report.technician.team',
            'report.manage', 'report.audit.view', 'report.export',
        ] as $permission) {
            $this->assertTrue(Permission::query()->where('name', $permission)->exists(), "{$permission} is not installed");
        }
    }

    public function test_report_permissions_are_mapped_per_template(): void
    {
        $perReport = ['report.business.view', 'report.asset.view', 'report.sla.view', 'report.stock_card.view', 'report.dictionary.view'];

        $this->assertEqualsCanonicalizing(Permission::query()->where('name', 'like', 'report.%')->pluck('name')->all(), $this->reportPermissionsOf('admin'));
        $this->assertEqualsCanonicalizing(['report.view', 'report.technician', 'report.technician.team', 'report.sales.view', 'report.export', ...$perReport], $this->reportPermissionsOf('manager'));
        $this->assertEqualsCanonicalizing(['report.view', ...$perReport], $this->reportPermissionsOf('staff'));
        $this->assertEqualsCanonicalizing(['report.view', 'report.sla.view'], $this->reportPermissionsOf('noc'));
        $this->assertEqualsCanonicalizing(['report.view', 'report.stock_card.view'], $this->reportPermissionsOf('warehouse'));
        $this->assertEqualsCanonicalizing(['report.view', 'report.business.view'], $this->reportPermissionsOf('finance'));
        $this->assertSame(['report.technician'], $this->reportPermissionsOf('technician'));
        $this->assertSame([], $this->reportPermissionsOf('customer'));
    }

    public function test_sales_report_permission_is_held_by_admin_and_manager_only(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'manager'], $this->holdersOf('report.sales.view'));
    }

    public function test_report_export_permission_is_held_by_admin_and_manager_only(): void
    {
        $this->assertEqualsCanonicalizing(['admin', 'manager'], $this->holdersOf('report.export'));
    }

    public function test_customer_sales_attribution_permissions_are_installed_and_mapped_per_template(): void
    {
        foreach (['customer.sales.assign', 'customer.sales.correct'] as $permission) {
            $this->assertTrue(Permission::query()->where('name', $permission)->exists(), "{$permission} is not installed");
        }

        $this->assertEqualsCanonicalizing(['admin', 'manager', 'noc', 'staff'], $this->holdersOf('customer.sales.assign'));
        $this->assertEqualsCanonicalizing(['admin', 'manager'], $this->holdersOf('customer.sales.correct'));
    }

    public function test_subscription_block_permission_is_held_by_admin_manager_and_noc_only(): void
    {
        $this->assertTrue(Permission::query()->where('name', 'customer.subscription.block')->exists());
        $this->assertEqualsCanonicalizing(['admin', 'manager', 'noc'], $this->holdersOf('customer.subscription.block'));
    }

    public function test_ticket_attachment_upload_is_held_by_the_templates_that_work_tickets(): void
    {
        $this->assertTrue(Permission::query()->where('name', 'ticket.attachment.upload')->exists());

        // The two app personas upload through the mobile API (docs/SECURITY.md 4.7); the manager never did.
        $this->assertEqualsCanonicalizing(['admin', 'noc', 'staff', 'technician', 'customer'], $this->holdersOf('ticket.attachment.upload'));
    }

    public function test_seeding_again_changes_no_permission_and_no_template(): void
    {
        $permissions = Permission::query()->count();
        $before = array_map(fn (string $key): array => $this->permissionsOf($key), array_combine(self::TEMPLATES, self::TEMPLATES));

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($permissions, Permission::query()->count());
        $this->assertSame(8, RoleTemplate::query()->count());

        foreach (self::TEMPLATES as $key) {
            $this->assertEqualsCanonicalizing($before[$key], $this->permissionsOf($key), $key);
        }
    }

    public function test_a_template_is_never_overwritten(): void
    {
        $staff = RoleTemplate::query()->where('key', 'staff')->firstOrFail();
        $staff->forceFill(['name' => 'Staf Lapangan'])->save();
        $staff->permissions()->sync(Permission::query()->whereIn('name', ['dashboard.view', 'report.audit.view'])->pluck('id')->all());

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame('Staf Lapangan', $staff->fresh()->name);
        $this->assertEqualsCanonicalizing(['dashboard.view', 'report.audit.view'], $this->permissionsOf('staff'));
        $this->assertSame(1, RoleTemplate::query()->where('key', 'staff')->count());
    }

    public function test_a_deleted_default_template_comes_back_unless_its_name_was_taken(): void
    {
        RoleTemplate::query()->where('key', 'warehouse')->delete();
        RoleTemplate::query()->where('key', 'finance')->delete();
        $taken = new RoleTemplate();
        $taken->forceFill(['key' => 'keuangan_cabang', 'name' => 'Keuangan'])->save();

        $this->seed(RolePermissionSeeder::class);

        $this->assertTrue(RoleTemplate::query()->where('key', 'warehouse')->exists(), 'a default the operator deleted is installed again');
        $this->assertFalse(RoleTemplate::query()->where('key', 'finance')->exists(), 'but not under a name another template already has');
        $this->assertSame(1, RoleTemplate::query()->where('name', 'Keuangan')->count());
    }

    public function test_seeding_never_changes_the_roles_of_a_company(): void
    {
        $company = Company::factory()->create();
        $roles = $this->companyRoles($company);
        $roles['manager']->syncPermissions(['dashboard.view']);
        $roles['staff']->forceFill(['name' => 'Staf Gudang'])->save();
        $custom = $this->companyRole($company, 'Penyelia', ['customer.view']);
        $before = Role::withoutCompany()->with('permissions')->orderBy('id')->get()->mapWithKeys(fn (Role $role): array => [$role->id => [$role->name, $role->permissions->pluck('name')->sort()->values()->all()]])->all();

        $this->seed(RolePermissionSeeder::class);

        $after = Role::withoutCompany()->with('permissions')->orderBy('id')->get()->mapWithKeys(fn (Role $role): array => [$role->id => [$role->name, $role->permissions->pluck('name')->sort()->values()->all()]])->all();

        $this->assertSame($before, $after);
        $this->assertSame(['customer.view'], $custom->fresh()->permissions->pluck('name')->all());
    }

    /** @return list<string> the permissions of a template as the database holds them */
    private function permissionsOf(string $key): array
    {
        return RoleTemplate::query()->where('key', $key)->firstOrFail()->permissions()->pluck('name')->all();
    }

    /** @return list<string> */
    private function reportPermissionsOf(string $key): array
    {
        return RoleTemplate::query()->where('key', $key)->firstOrFail()->permissions()->where('name', 'like', 'report.%')->pluck('name')->all();
    }

    /** @return list<string> the keys of the templates that hold the permission */
    private function holdersOf(string $permission): array
    {
        return RoleTemplate::query()
            ->whereHas('permissions', fn ($query) => $query->where('name', $permission))
            ->orderBy('id')
            ->pluck('key')
            ->all();
    }
}
