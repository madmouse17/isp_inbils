<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\OrganizationUnit;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrganizationServerTableExportTest extends TestCase
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

    public function test_index_is_company_scoped_and_searches(): void
    {
        OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'HQ Branch',
            'code' => 'HQ-001',
        ]);
        OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Remote Branch',
            'code' => 'RMT-001',
        ]);
        OrganizationUnit::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'HQ Other',
            'code' => 'HQ-OTHER',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.organizations.index', ['search' => 'HQ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Organizations/Index')
                ->has('organizations.data', 1)
                ->where('organizations.data.0.code', 'HQ-001')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Org Export Unit',
            'code' => 'ORG-EXP',
        ]);
        OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Org Skip Unit',
            'code' => 'ORG-SKP',
        ]);
        OrganizationUnit::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Org Export Unit',
            'code' => 'ORG-OTHER',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.organizations.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('ORG-EXP', $content);
        $this->assertStringNotContainsString('ORG-SKP', $content);
        $this->assertStringNotContainsString('ORG-OTHER', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        OrganizationUnit::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Org PDF Unit',
            'code' => 'ORG-PDF',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.organizations.export', ['format' => 'pdf']));

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
        $role = Role::firstOrCreate(['name' => 'org-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('organization.view', 'web');
        $role->syncPermissions(['organization.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.organizations.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
