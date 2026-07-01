<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\Core\Vehicle;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SystemSettingSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_create_employee_profile(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $org = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id]);

        $response = $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'employee_number' => 'EMP-0001',
            'phone' => '08123456789',
            'status' => 'active',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-0001', 'user_id' => $user->id]);
    }

    public function test_employee_number_unique_per_company(): void
    {
        $user1 = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => $user1->id,
            'employee_number' => 'EMP-DUP',
        ]);

        $user2 = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $response = $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user2->id,
            'employee_number' => 'EMP-DUP',
            'status' => 'active',
        ]);

        $response->assertSessionHasErrors(['employee_number']);
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.employees.index'));
        $response->assertOk();
    }
}
