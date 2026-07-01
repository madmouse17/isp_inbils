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

    public function test_no_users_seeded(): void
    {
        $this->assertSame(0, User::count());
    }
}
