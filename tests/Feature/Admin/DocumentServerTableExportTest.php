<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\DocumentType;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentServerTableExportTest extends TestCase
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
        DocumentType::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'ID Card',
            'code' => 'IDC-001',
        ]);
        DocumentType::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Contract',
            'code' => 'CTR-001',
        ]);
        DocumentType::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'ID Card',
            'code' => 'IDC-OTHER',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.documents.index', ['search' => 'ID Card']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Documents/Index')
                ->has('documentTypes.data', 1)
                ->where('documentTypes.data.0.code', 'IDC-001')
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        DocumentType::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Doc Export Type',
            'code' => 'DOC-EXP',
        ]);
        DocumentType::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Doc Skip Type',
            'code' => 'DOC-SKP',
        ]);
        DocumentType::factory()->create([
            'company_id' => $this->otherCompany->id,
            'name' => 'Doc Export Type',
            'code' => 'DOC-OTHER',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.documents.export', [
                'format' => 'csv',
                'search' => 'Export',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('DOC-EXP', $content);
        $this->assertStringNotContainsString('DOC-SKP', $content);
        $this->assertStringNotContainsString('DOC-OTHER', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        DocumentType::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Doc PDF Type',
            'code' => 'DOC-PDF',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.documents.export', ['format' => 'pdf']));

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
        $role = Role::firstOrCreate(['name' => 'doc-no-export', 'guard_name' => 'web']);
        Permission::findOrCreate('organization.view', 'web');
        $role->syncPermissions(['organization.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.documents.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
