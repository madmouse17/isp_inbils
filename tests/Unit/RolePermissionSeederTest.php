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

    public function test_admin_has_all_permissions(): void
    {
        $admin = Role::where('name', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertSame(Permission::count(), $admin->permissions->count());
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

        foreach (['inventory.stock.receive', 'inventory.stock.issue', 'inventory.stock.transfer', 'inventory.stock.adjust'] as $permission) {
            $this->assertTrue(Permission::where('name', $permission)->exists());
            $this->assertTrue($admin->hasPermissionTo($permission));
            $this->assertTrue($manager->hasPermissionTo($permission));
        }

        $this->assertTrue($staff->hasPermissionTo('inventory.stock.receive'));
        $this->assertTrue($staff->hasPermissionTo('inventory.stock.issue'));
        $this->assertFalse($staff->hasPermissionTo('inventory.stock.transfer'));
        $this->assertFalse($staff->hasPermissionTo('inventory.stock.adjust'));
        $this->assertTrue($technician->hasPermissionTo('inventory.stock.issue'));
    }

    public function test_no_users_seeded(): void
    {
        $this->assertSame(0, User::count());
    }
}
