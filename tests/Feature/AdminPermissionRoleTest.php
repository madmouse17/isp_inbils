<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminPermissionRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissions_index_paginates_permissions_and_keeps_group_data(): void
    {
        $this->actingAs($this->userWithPermissions(Company::factory()->create(['is_active' => true]), 'roles.manage'));

        foreach (range(1, 16) as $number) {
            Permission::create(['name' => sprintf('alpha.%02d', $number), 'guard_name' => 'web']);
        }

        // The catalogue holds far more permissions than these sixteen: the search narrows the table to them.
        $this->get(route('admin.permissions.index', ['search' => 'alpha.']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->has('permissions.data', 10)
                ->where('permissions.data.0.group', 'alpha')
                ->where('permissions.meta.current_page', 1)
                ->where('permissions.meta.last_page', 2));

        $this->get(route('admin.permissions.index', ['search' => 'alpha.', 'page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->has('permissions.data', 6)
                ->where('permissions.meta.current_page', 2)
                ->where('permissions.meta.last_page', 2)
                ->where('permissions.data.0.name', 'alpha.11')
                ->where('permissions.data.0.group', 'alpha'));
    }

    public function test_permissions_index_supports_server_side_search_sort_and_per_page(): void
    {
        $this->actingAs($this->userWithPermissions(Company::factory()->create(['is_active' => true]), 'roles.manage'));

        Permission::create(['name' => 'zeta.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'zeta.update', 'guard_name' => 'web']);
        Permission::create(['name' => 'omega.view', 'guard_name' => 'web']);

        $this->get(route('admin.permissions.index', [
            'search' => 'zeta',
            'sort' => 'name',
            'direction' => 'desc',
            'per_page' => 25,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Permissions/Index')
                ->has('permissions.data', 2)
                ->where('permissions.data.0.name', 'zeta.view')
                ->where('permissions.data.1.name', 'zeta.update')
                ->where('permissions.meta.per_page', 25)
                ->where('filters.search', 'zeta')
                ->where('filters.sort', 'name')
                ->where('filters.direction', 'desc')
                ->where('filters.per_page', '25'));
    }

    public function test_role_show_receives_permissions_group_data_and_selected_permissions(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create(['is_active' => true]);
        // He holds what the role holds, so the page offers him its edit and delete.
        $this->actingAs($this->userWithPermissions($company, 'roles.manage', 'billing.view'));

        $role = $this->companyRole($company, 'support', ['billing.view']);

        // The page is a React file the tenant roles UI ticket adds, hence `false`.
        $this->get(route('admin.roles.show', $role))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Show', false)
                ->where('role.data.permissions.0', 'billing.view')
                ->where('role.data.template_key', null)
                ->where('role.data.can', ['update' => true, 'delete' => true])
                // The catalogue is one for every company: look the rows up by name.
                ->where('permissions.data', fn ($rows): bool => collect($rows)->pluck('group', 'name')->only(['billing.view', 'inventory.view'])->all()
                    === ['billing.view' => 'billing', 'inventory.view' => 'inventory']));
    }

    public function test_the_permission_picker_tells_which_permissions_the_viewer_may_grant(): void
    {
        $this->withoutVite();
        $company = Company::factory()->create(['is_active' => true]);
        $this->actingAs($this->userWithPermissions($company, 'roles.manage', 'ticket.view'));

        $this->get(route('admin.roles.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Create', false)
                ->where('permissions.data', function ($rows): bool {
                    $grantable = collect($rows)->pluck('grantable', 'name');

                    // Held, a marker (it opens nothing alone) and not held: the picker switches off the last kind only.
                    return $grantable['ticket.view'] === true
                        && $grantable['evaluation.evaluable'] === true
                        && $grantable['billing.update'] === false;
                })
                ->has('templates'));
    }

    private function userWithPermissions(Company $company, string ...$permissions): User
    {
        $user = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Web access is a permission of its own: without it every /admin page is the "use the app" page.
        return $this->grantPermissions($user, ['app.web', ...$permissions], $company);
    }
}
