<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PermissionServerTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');
        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_search_sort_and_pagination_preserve_the_global_permission_catalogue(): void
    {
        for ($number = 1; $number <= 26; $number++) {
            Permission::findOrCreate(sprintf('table-boundary.%02d', $number), 'web');
        }
        Permission::findOrCreate('outside-table-search', 'web');

        $otherAdmin = User::factory()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $otherAdmin->assignRole('admin');

        foreach ([$this->admin, $otherAdmin] as $actor) {
            $this->actingAs($actor)
                ->get(route('admin.permissions.index', [
                    'search' => 'table-boundary.',
                    'sort' => 'name',
                    'direction' => 'desc',
                    'per_page' => 25,
                    'page' => 2,
                ]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Permissions/Index')
                    ->has('permissions.data', 1)
                    ->where('permissions.data.0.name', 'table-boundary.01')
                    ->where('permissions.meta.total', 26)
                    ->where('permissions.meta.current_page', 2)
                    ->where('permissions.meta.per_page', 25)
                    ->where('permissions.links.prev', function ($url): bool {
                        parse_str(parse_url($url, PHP_URL_QUERY), $query);

                        return $query === [
                            'search' => 'table-boundary.',
                            'sort' => 'name',
                            'direction' => 'desc',
                            'per_page' => '25',
                            'page' => '1',
                        ];
                    })
                );
        }
    }

    public function test_invalid_sort_direction_and_page_size_fall_back_safely(): void
    {
        foreach (['zulu', 'alpha'] as $name) {
            Permission::findOrCreate('table-invalid.'.$name, 'web');
        }

        $this->actingAs($this->admin)
            ->get(route('admin.permissions.index', [
                'search' => 'table-invalid.',
                'sort' => 'not_a_column',
                'direction' => 'not_a_direction',
                'per_page' => 1000,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('permissions.data', 2)
                ->where('permissions.data.0.name', 'table-invalid.alpha')
                ->where('permissions.data.1.name', 'table-invalid.zulu')
                ->where('permissions.meta.per_page', 10)
            );
    }

    public function test_index_is_forbidden_without_roles_manage_even_with_users_manage(): void
    {
        $user = $this->userWithPermission('users.manage');

        $this->actingAs($user)
            ->get(route('admin.permissions.index'))
            ->assertForbidden();
    }

    public function test_index_is_forbidden_without_any_permission(): void
    {
        $this->actingAs($this->userWithPermission(null))
            ->get(route('admin.permissions.index'))
            ->assertForbidden();
    }

    public function test_index_opens_with_roles_manage_alone(): void
    {
        $this->actingAs($this->userWithPermission('roles.manage'))
            ->get(route('admin.permissions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Permissions/Index'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.permissions.index'))->assertRedirect(route('login'));
    }

    private function userWithPermission(?string $permission): User
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        if ($permission !== null) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
