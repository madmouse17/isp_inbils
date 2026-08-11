<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleServerTableExportTest extends TestCase
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

    public function test_index_paginates_and_searches_roles(): void
    {
        Role::findOrCreate('alpha-role', 'web');
        Role::findOrCreate('beta-role', 'web');
        Role::findOrCreate('gamma-other', 'web');

        $this->actingAs($this->admin)
            ->get(route('admin.roles.index', ['search' => 'role', 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Index')
                ->has('roles.data')
                ->where('can.export', true)
                ->where('filters.search', 'role')
            );

        $this->actingAs($this->admin)
            ->get(route('admin.roles.index', ['search' => 'alpha-role']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Index')
                ->has('roles.data', 1)
                ->where('roles.data.0.name', 'alpha-role')
            );
    }

    public function test_export_csv_streams_filtered_roles(): void
    {
        Role::findOrCreate('export-role-unique', 'web');
        Role::findOrCreate('skip-role-unique', 'web');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.roles.export', [
                'format' => 'csv',
                'search' => 'export-role-unique',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('export-role-unique', $content);
        $this->assertStringNotContainsString('skip-role-unique', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        Role::findOrCreate('pdf-role-unique', 'web');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.roles.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'roles-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('roles.view', 'web');
        $role->syncPermissions(['roles.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.roles.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
