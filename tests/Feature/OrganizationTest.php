<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Services\Core\OrganizationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationTest extends TestCase
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
        CompanyService::resetCache();
    }

    public function test_create_organization_unit(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.organizations.store'), [
            'code' => 'HQ',
            'name' => 'Headquarters',
            'type' => 'company',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organization_units', ['code' => 'HQ', 'name' => 'Headquarters']);
    }

    public function test_organization_path_computed_via_service(): void
    {
        CompanyService::useCompanyForTests((int) $this->admin->company_id);

        $parent = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'BRANCH',
            'name' => 'Branch',
            'type' => 'branch',
            'is_active' => true,
        ]);

        $child = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'AREA',
            'name' => 'Area',
            'type' => 'area',
            'parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $this->assertEquals('BRANCH', $parent->fresh()->path);
        $this->assertEquals('BRANCH > AREA', $child->fresh()->path);
    }

    public function test_update_organization_unit(): void
    {
        $unit = OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'OLD',
            'name' => 'Old Unit',
            'type' => 'branch',
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.organizations.update', $unit), [
            'code' => 'NEW',
            'name' => 'New Unit',
            'type' => 'area',
            'is_active' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('organization_units', [
            'id' => $unit->id,
            'code' => 'NEW',
            'name' => 'New Unit',
            'type' => 'area',
        ]);
    }

    public function test_cycle_prevention(): void
    {
        $this->actingAs($this->admin);
        CompanyService::useCompanyForTests((int) $this->admin->company_id);

        $parent = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'P1', 'name' => 'P1', 'type' => 'branch', 'is_active' => true,
        ]);
        $child = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'C1', 'name' => 'C1', 'type' => 'area',
            'parent_id' => $parent->id, 'is_active' => true,
        ]);

        try {
            OrganizationService::move($parent, $child->id);
            $this->fail('Should have thrown cycle exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }
    }

    public function test_move_rejects_foreign_tenant_parent(): void
    {
        CompanyService::useCompanyForTests((int) $this->admin->company_id);

        $child = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'LOCAL',
            'name' => 'Local',
            'type' => 'branch',
            'is_active' => true,
        ]);
        $foreignParent = OrganizationUnit::withoutCompany()->create([
            'company_id' => Company::factory()->create(['is_active' => true])->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'type' => 'branch',
            'is_active' => true,
        ]);

        try {
            OrganizationService::move($child, $foreignParent->id);
            $this->fail('Should have thrown HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        $this->assertSame(null, $child->fresh()->parent_id);
    }

    public function test_delete_unit_with_children_fails(): void
    {
        $this->actingAs($this->admin);
        $parent = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'P2', 'name' => 'P2', 'type' => 'branch', 'is_active' => true,
        ]);
        OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'C2', 'name' => 'C2', 'type' => 'area',
            'parent_id' => $parent->id, 'is_active' => true,
        ]);

        try {
            OrganizationService::delete($parent);
            $this->fail('Should have thrown validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('organization', $e->errors());
        }
    }

    public function test_delete_organization_unit(): void
    {
        $unit = OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'DEL',
            'name' => 'Delete Unit',
            'type' => 'branch',
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.organizations.destroy', $unit));

        $response->assertRedirect();
        $this->assertSoftDeleted($unit);
    }

    public function test_delete_is_refused_while_employees_belong_to_the_unit(): void
    {
        $unit = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'HR', 'type' => 'unit']);
        $employee = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id])->id,
            'organization_id' => $unit->id,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.organizations.destroy', $unit))
            ->assertUnprocessable()
            ->assertJsonPath('errors.organization.0', 'Unit masih punya karyawan. Pindahkan karyawannya ke unit lain dulu.');

        $this->assertNotSoftDeleted($unit);
        $this->assertSame($unit->id, $employee->fresh()->organization_id);
    }

    public function test_delete_works_once_the_employees_moved_away(): void
    {
        $unit = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'HR2', 'type' => 'unit']);
        $employee = EmployeeProfile::factory()->create([
            'company_id' => $this->admin->company_id,
            'user_id' => User::factory()->create(['company_id' => $this->admin->company_id])->id,
            'organization_id' => $unit->id,
        ]);
        $employee->update(['organization_id' => null]);

        $this->actingAs($this->admin)->delete(route('admin.organizations.destroy', $unit))->assertRedirect();

        $this->assertSoftDeleted($unit);
    }

    public function test_delete_is_refused_while_the_unit_has_children_with_an_indonesian_message(): void
    {
        $parent = OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'PAR', 'type' => 'branch']);
        OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'KID', 'type' => 'area', 'parent_id' => $parent->id]);

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.organizations.destroy', $parent))
            ->assertUnprocessable()
            ->assertJsonPath('errors.organization.0', 'Unit masih punya sub-unit. Pindahkan atau hapus sub-unitnya dulu.');
    }

    public function test_type_must_be_one_of_the_known_types(): void
    {
        $this->actingAs($this->admin)->post(route('admin.organizations.store'), [
            'code' => 'ODD',
            'name' => 'Odd',
            'type' => 'galaxy',
        ])->assertSessionHasErrors(['type' => 'jenis yang dipilih tidak valid.']);

        $this->assertDatabaseMissing('organization_units', ['code' => 'ODD']);
    }

    public function test_code_is_unique_within_the_company(): void
    {
        OrganizationUnit::factory()->create(['company_id' => $this->admin->company_id, 'code' => 'DUP', 'type' => 'branch']);

        $this->actingAs($this->admin)->post(route('admin.organizations.store'), [
            'code' => 'DUP',
            'name' => 'Another',
            'type' => 'branch',
        ])->assertSessionHasErrors(['code' => 'kode sudah digunakan.']);
    }

    public function test_company_scope_isolation(): void
    {
        $this->actingAs($this->admin);
        CompanyService::resetCache();

        // Create org in other company directly (bypass scope)
        $otherCompany = Company::factory()->create();
        $otherOrg = OrganizationUnit::withoutCompany()->create([
            'company_id' => $otherCompany->id,
            'code' => 'OTHER',
            'name' => 'Other',
            'type' => 'branch',
            'is_active' => true,
        ]);

        // Query via controller (has BelongsToCompany scope)
        $visible = OrganizationUnit::query()->count();
        $this->assertEquals(0, $visible, 'Other company org should not be visible');
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.organizations.index'));
        $response->assertOk();
    }
}
