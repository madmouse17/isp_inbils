<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPermissionRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_index_paginates_permissions_and_keeps_group_data(): void
    {
        $this->actingAs($this->userWithPermissions('users.manage'));

        foreach (range(1, 16) as $number) {
            Permission::create(['name' => sprintf('alpha.%02d', $number), 'guard_name' => 'web']);
        }

        $this->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->has('permissions.data', 10)
                ->where('permissions.data.0.group', 'alpha')
                ->where('permissions.meta.current_page', 1)
                ->where('permissions.meta.last_page', 2));

        $this->get(route('admin.permissions.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->where('permissions.meta.current_page', 2)
                ->where('permissions.meta.last_page', 2)
                ->where('permissions.data.0.name', 'alpha.11')
                ->where('permissions.data.0.group', 'alpha'));
    }

    public function test_role_edit_receives_permissions_group_data_and_selected_permissions(): void
    {
        $this->actingAs($this->userWithPermissions('roles.manage'));

        Permission::create(['name' => 'billing.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'inventory.view', 'guard_name' => 'web']);

        $role = Role::create(['name' => 'support', 'guard_name' => 'web']);
        $role->givePermissionTo('billing.view');

        $this->get(route('admin.roles.edit', $role))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Edit')
                ->where('role.data.permissions.0', 'billing.view')
                ->where('permissions.data.0.name', 'billing.view')
                ->where('permissions.data.0.group', 'billing')
                ->where('permissions.data.1.name', 'inventory.view')
                ->where('permissions.data.1.group', 'inventory'));
    }

    private function userWithPermissions(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['guard_name' => 'web']);
        }

        app()['cache']->forget('spatie.permission.cache');

        $user = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
