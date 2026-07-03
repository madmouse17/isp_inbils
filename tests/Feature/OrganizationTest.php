<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use App\Services\Core\OrganizationService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SystemSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        \App\Services\Core\CompanyService::resetCache();
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

    public function test_cycle_prevention(): void
    {
        $parent = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'P1', 'name' => 'P1', 'type' => 'branch', 'is_active' => true,
        ]);
        $child = OrganizationService::create([
            'company_id' => $this->admin->company_id,
            'code' => 'C1', 'name' => 'C1', 'type' => 'area',
            'parent_id' => $parent->id, 'is_active' => true,
        ]);

        $this->actingAs($this->admin);

        try {
            OrganizationService::move($parent, $child->id);
            $this->fail('Should have thrown cycle exception');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('parent_id', $e->errors());
        }
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
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('organization', $e->errors());
        }
    }

    public function test_company_scope_isolation(): void
    {
        $this->actingAs($this->admin);
        \App\Services\Core\CompanyService::resetCache();

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
