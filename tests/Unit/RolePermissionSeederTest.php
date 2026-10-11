<?php

namespace Tests\Unit;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_creates_five_roles(): void
    {
        $this->assertSame(5, Role::count());
    }

    public function test_creates_at_least_80_permissions(): void
    {
        $this->assertGreaterThanOrEqual(80, Permission::count());
    }

    public function test_admin_has_every_permission_except_the_customer_portal(): void
    {
        $admin = Role::where('name', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertEqualsCanonicalizing(
            Permission::query()->whereNotIn('name', ['portal.invoice.view', 'portal.invoice.pay'])->pluck('name')->all(),
            $admin->permissions->pluck('name')->all(),
        );
    }

    public function test_portal_invoice_permissions_belong_to_the_customer_role_only(): void
    {
        foreach (['admin' => false, 'manager' => false, 'staff' => false, 'technician' => false, 'customer' => true] as $role => $holds) {
            foreach (['portal.invoice.view', 'portal.invoice.pay'] as $permission) {
                $this->assertSame($holds, Role::findByName($role)->hasPermissionTo($permission), "$role $permission");
            }
        }
    }

    public function test_two_factor_reset_permission_is_admin_only(): void
    {
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('user.two_factor.reset'));
        foreach (['manager', 'staff', 'technician', 'customer'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('user.two_factor.reset'), $role);
        }
    }

    public function test_admin_has_customer_manage_permission(): void
    {
        $admin = Role::where('name', 'admin')->first();
        $this->assertTrue($admin->hasPermissionTo('customer.manage'));
    }

    public function test_inventory_stock_permissions_are_seeded_and_mapped(): void
    {
        $admin = Role::where('name', 'admin')->firstOrFail();
        $manager = Role::where('name', 'manager')->firstOrFail();
        $staff = Role::where('name', 'staff')->firstOrFail();
        $technician = Role::where('name', 'technician')->firstOrFail();

        foreach (['inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer', 'inventory.stock.adjust', 'inventory.stock.adjust.request', 'inventory.stock.adjust.approve'] as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists());
            $this->assertTrue($admin->hasPermissionTo($permission));
        }

        // SoD: Manager is controller, Staff is operator
        $this->assertTrue($manager->hasPermissionTo('inventory.stock.adjust.approve'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.adjust'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.receive'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.issue'));
        $this->assertFalse($manager->hasPermissionTo('inventory.stock.transfer'));

        $this->assertTrue($staff->hasPermissionTo('inventory.stock.receive'));
        $this->assertTrue($staff->hasPermissionTo('inventory.stock.issue'));
        $this->assertTrue($staff->hasPermissionTo('inventory.stock.transfer'));
        $this->assertTrue($staff->hasPermissionTo('inventory.stock.adjust.request'));
        $this->assertFalse($staff->hasPermissionTo('inventory.stock.adjust.approve'));
        $this->assertTrue($technician->hasPermissionTo('inventory.stock.issue'));
    }

    public function test_report_permissions_are_seeded(): void
    {
        foreach ([
            'report.view', 'report.business.view', 'report.sales.view', 'report.asset.view', 'report.sla.view',
            'report.stock_card.view', 'report.dictionary.view', 'report.technician',
            'report.manage', 'report.audit.view', 'report.export',
        ] as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists(), "{$permission} is not seeded");
        }
    }

    public function test_report_permissions_are_mapped_per_role(): void
    {
        $perReport = [
            'report.business.view', 'report.asset.view', 'report.sla.view',
            'report.stock_card.view', 'report.dictionary.view',
        ];

        $this->assertEqualsCanonicalizing(
            Permission::where('name', 'like', 'report.%')->pluck('name')->all(),
            $this->reportPermissionsOf('admin'),
        );
        $this->assertEqualsCanonicalizing(
            ['report.view', 'report.technician', 'report.sales.view', 'report.export', ...$perReport],
            $this->reportPermissionsOf('manager'),
        );
        $this->assertEqualsCanonicalizing(
            ['report.view', ...$perReport],
            $this->reportPermissionsOf('staff'),
        );
        $this->assertSame(['report.technician'], $this->reportPermissionsOf('technician'));
        $this->assertSame([], $this->reportPermissionsOf('customer'));
    }

    public function test_sales_report_permission_is_held_by_admin_and_manager_only(): void
    {
        foreach (['admin' => true, 'manager' => true, 'staff' => false, 'technician' => false, 'customer' => false] as $role => $holds) {
            $this->assertSame(
                $holds,
                Role::where('name', $role)->firstOrFail()->hasPermissionTo('report.sales.view'),
                $role,
            );
        }
    }

    public function test_report_export_permission_is_held_by_admin_and_manager_only(): void
    {
        foreach (['admin' => true, 'manager' => true, 'staff' => false, 'technician' => false, 'customer' => false] as $role => $holds) {
            $this->assertSame(
                $holds,
                Role::where('name', $role)->firstOrFail()->hasPermissionTo('report.export'),
                $role,
            );
        }
    }

    public function test_customer_sales_attribution_permissions_are_seeded_and_mapped_per_role(): void
    {
        $expected = [
            'admin' => ['customer.sales.assign', 'customer.sales.correct'],
            'manager' => ['customer.sales.assign', 'customer.sales.correct'],
            'staff' => ['customer.sales.assign'],
            'technician' => [],
            'customer' => [],
        ];

        foreach (['customer.sales.assign', 'customer.sales.correct'] as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists(), "{$permission} is not seeded");
        }

        foreach ($expected as $role => $permissions) {
            $this->assertEqualsCanonicalizing($permissions, $this->salesPermissionsOf($role), $role);
        }
    }

    public function test_seeding_again_keeps_the_customer_sales_grants_unchanged(): void
    {
        $before = ['manager' => $this->salesPermissionsOf('manager'), 'staff' => $this->salesPermissionsOf('staff')];

        $this->seed(RolePermissionSeeder::class);

        $this->assertEqualsCanonicalizing($before['manager'], $this->salesPermissionsOf('manager'));
        $this->assertEqualsCanonicalizing($before['staff'], $this->salesPermissionsOf('staff'));
        $this->assertSame(1, Permission::where('name', 'customer.sales.assign')->count());
        $this->assertSame(1, Permission::where('name', 'customer.sales.correct')->count());
    }

    public function test_seeding_again_keeps_permissions_and_report_grants_unchanged(): void
    {
        $roles = ['admin', 'manager', 'staff', 'technician', 'customer'];
        $permissionCount = Permission::count();
        $before = [];
        foreach ($roles as $role) {
            $before[$role] = $this->reportPermissionsOf($role);
        }

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame($permissionCount, Permission::count());
        foreach ($roles as $role) {
            $this->assertEqualsCanonicalizing($before[$role], $this->reportPermissionsOf($role), $role);
        }
    }

    /** @return list<string> */
    private function reportPermissionsOf(string $roleName): array
    {
        return Role::where('name', $roleName)->firstOrFail()
            ->permissions()
            ->where('name', 'like', 'report.%')
            ->pluck('name')
            ->all();
    }

    /** @return list<string> */
    private function salesPermissionsOf(string $roleName): array
    {
        return Role::where('name', $roleName)->firstOrFail()
            ->permissions()
            ->where('name', 'like', 'customer.sales.%')
            ->pluck('name')
            ->all();
    }

    public function test_no_users_seeded(): void
    {
        $this->assertSame(0, User::count());
    }
}
