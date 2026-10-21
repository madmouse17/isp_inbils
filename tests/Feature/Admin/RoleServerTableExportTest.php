<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RoleServerTableExportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['is_active' => true]);
        $this->admin = $this->grantRole(User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]), 'admin', $this->company);
    }

    public function test_index_paginates_and_searches_roles(): void
    {
        $this->companyRole($this->company, 'alpha-role', []);
        $this->companyRole($this->company, 'beta-role', []);
        $this->companyRole($this->company, 'gamma-other', []);

        $this->actingAs($this->admin)
            ->get(route('admin.roles.index', ['search' => 'role', 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Roles/Index')
                ->has('roles.data', 2)
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
        $this->companyRole($this->company, 'export-role-unique', []);
        $this->companyRole($this->company, 'skip-role-unique', []);

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

    public function test_the_export_holds_the_roles_of_the_company_only(): void
    {
        $this->companyRole($this->company, 'own-role-unique', []);
        $this->companyRole(Company::factory()->create(['is_active' => true]), 'foreign-role-unique', []);

        $content = $this->actingAs($this->admin)
            ->get(route('admin.roles.export', ['format' => 'csv']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('own-role-unique', $content);
        $this->assertStringNotContainsString('foreign-role-unique', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        $this->companyRole($this->company, 'pdf-role-unique', []);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.roles.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
    }

    public function test_export_forbidden_without_permission(): void
    {
        Permission::findOrCreate('roles.view', 'web');
        $user = $this->grantPermissions(User::factory()->create([
            'company_id' => $this->company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]), ['app.web', 'roles.view'], $this->company);

        $this->actingAs($user)
            ->get(route('admin.roles.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
