<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
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
}
