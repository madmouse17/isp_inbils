<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);

        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'name' => 'Zzzz Admin',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    private function makeEmployee(int $companyId, string $number, string $name, string $status = 'active'): EmployeeProfile
    {
        $user = User::factory()->create([
            'company_id' => $companyId,
            'name' => $name,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        return EmployeeProfile::query()->forceCreate([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'employee_number' => $number,
            'status' => $status,
        ]);
    }

    public function test_index_is_company_scoped_and_searchable(): void
    {
        $this->makeEmployee((int) $this->admin->company_id, 'EMP-OWN-1', 'Alice Own');
        $this->makeEmployee((int) $this->admin->company_id, 'EMP-OWN-2', 'Bob Own', 'inactive');
        $this->makeEmployee((int) $this->otherCompany->id, 'EMP-OTHER', 'Other Co');

        $this->actingAs($this->admin)
            ->get(route('admin.employees.index', [
                'search' => 'EMP-OWN',
                'status' => 'active',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Employees/Index')
                ->has('employees.data', 1)
                ->where('employees.data.0.employee_number', 'EMP-OWN-1')
                ->where('can.export', true)
                ->where('filters.search', 'EMP-OWN')
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $this->makeEmployee((int) $this->admin->company_id, 'EMP-CSV-1', 'Export Me');
        $this->makeEmployee((int) $this->admin->company_id, 'EMP-CSV-2', 'Skip Me', 'inactive');
        $this->makeEmployee((int) $this->otherCompany->id, 'EMP-CSV-OTHER', 'Export Me');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.employees.export', [
                'format' => 'csv',
                'search' => 'Export Me',
                'status' => 'active',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('EMP-CSV-1', $content);
        $this->assertStringNotContainsString('EMP-CSV-2', $content);
        $this->assertStringNotContainsString('EMP-CSV-OTHER', $content);
    }

    public function test_sorted_pagination_and_export_share_organization_and_status_filters(): void
    {
        $organization = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id]);
        $numbers = [];
        for ($number = 1; $number <= 11; $number++) {
            $employeeNumber = sprintf('PAGE-EMP-%02d', $number);
            $numbers[] = $employeeNumber;
            $this->makeEmployee((int) $this->admin->company_id, $employeeNumber, 'Page Employee')
                ->update(['organization_id' => $organization->id]);
        }
        $this->makeEmployee((int) $this->admin->company_id, 'PAGE-EMP-12', 'Wrong Organization');
        $this->makeEmployee((int) $this->admin->company_id, 'PAGE-EMP-13', 'Inactive', 'inactive')
            ->update(['organization_id' => $organization->id]);
        $this->makeEmployee((int) $this->otherCompany->id, 'PAGE-EMP-14', 'Other Tenant');

        $filters = [
            'search' => 'PAGE-EMP-',
            'organization_id' => $organization->id,
            'status' => 'active',
            'sort' => 'employee_number',
            'direction' => 'desc',
            'per_page' => 10,
            'page' => 2,
        ];
        $this->actingAs($this->admin)
            ->get(route('admin.employees.index', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('employees.data', 1)
                ->where('employees.data.0.employee_number', 'PAGE-EMP-01')
                ->where('employees.meta.total', 11)
                ->where('employees.meta.current_page', 2)
            );

        $response = $this->get(route('admin.employees.export', $filters + ['format' => 'csv']))->assertOk();
        $lines = preg_split('/\r?\n/', trim($response->streamedContent()));
        $this->assertSame('Employee Number', str_getcsv(ltrim((string) array_shift($lines), "\xEF\xBB\xBF"))[0]);
        $this->assertSame(array_reverse($numbers), array_map(static fn ($line) => str_getcsv($line)[0], $lines));
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $role = Role::firstOrCreate(['name' => 'employees-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('employees.view', 'web');
        $role->syncPermissions(['employees.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.employees.export', ['format' => 'csv']))
            ->assertForbidden();
    }

    public function test_users_dropdown_is_company_scoped(): void
    {
        // Create an unassigned user in admin's own company
        $ownUser = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Own Unassigned',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        // Create an unassigned user in a different company
        $otherUser = User::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Unassigned',
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Employees/Index')
                ->has('users.data', 2)
                ->where('users.data.0.name', 'Own Unassigned')
                ->where('users.data.1.name', $this->admin->name)
            );
    }
}
