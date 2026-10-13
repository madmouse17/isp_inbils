<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tests\Traits\AllowsManualCodes;

class EmployeeTest extends TestCase
{
    use AllowsManualCodes;
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

    public function test_create_without_a_number_gets_the_configured_format(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);
        $org = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'phone' => '08123456789',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-0001', 'user_id' => $user->id]);
    }

    public function test_create_with_a_number_is_rejected_while_manual_input_is_off(): void
    {
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->id,
            'employee_number' => 'EMP-TYPED',
            'status' => 'active',
        ])->assertSessionHasErrors(['employee_number']);

        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $user->id]);
    }

    public function test_create_keeps_a_typed_number_when_manual_input_is_on(): void
    {
        $this->setManualCodes($this->admin->company_id, true, 'employee');
        $user = User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true]);

        $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'user_id' => $user->id,
            'employee_number' => 'EMP-TYPED',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('employee_profiles', ['employee_number' => 'EMP-TYPED', 'user_id' => $user->id]);
    }

    public function test_update_ignores_the_number_while_manual_input_is_off(): void
    {
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
            'employee_number' => 'EMP-KEEP',
        ]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            'employee_number' => 'EMP-CHANGED',
            'phone' => '08111111111',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $profile->refresh();
        $this->assertSame('EMP-KEEP', $profile->employee_number);
        $this->assertSame('08111111111', $profile->phone);
    }

    public function test_update_changes_the_number_when_manual_input_is_on(): void
    {
        $this->setManualCodes($this->admin->company_id, true, 'employee');
        $profile = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id, 'is_active' => true])->id,
            'employee_number' => 'EMP-OLD',
        ]);

        $this->actingAs($this->admin)->put(route('admin.employees.update', $profile), [
            'employee_number' => 'EMP-NEW',
            'status' => 'active',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('EMP-NEW', $profile->fresh()->employee_number);
    }

    public function test_employee_number_unique_per_company(): void
    {
        $this->setManualCodes($this->admin->company_id, true, 'employee');
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

    public function test_index_exposes_the_code_sequence(): void
    {
        $this->actingAs($this->admin)->get(route('admin.employees.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('codeSequence.manual', false)
                ->where('codeSequence.preview', 'EMP-0001'));
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.employees.index'));
        $response->assertOk();
    }
}
