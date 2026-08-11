<?php

namespace Tests\Feature\Admin;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerServerTableExportTest extends TestCase
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

    public function test_index_is_company_scoped_and_filterable(): void
    {
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUS-OWN-1',
            'name' => 'Own Active',
            'type' => 'Individual',
            'is_active' => true,
        ]);
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUS-OWN-2',
            'name' => 'Own Inactive',
            'type' => 'Company',
            'is_active' => false,
        ]);
        Customer::factory()->create([
            'company_id' => $this->otherCompany->id,
            'code' => 'CUS-OTHER',
            'name' => 'Other Co',
            'type' => 'Individual',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.customers.index', [
                'status' => 'active',
                'search' => 'Own',
                'sort' => 'code',
                'direction' => 'asc',
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers/Index')
                ->has('customers.data', 1)
                ->where('customers.data.0.code', 'CUS-OWN-1')
                ->where('filters.status', 'active')
                ->where('filters.search', 'Own')
                ->where('filters.sort', 'code')
                ->where('filters.direction', 'asc')
                ->where('filters.per_page', '25')
                ->where('customers.meta.per_page', 25)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUS-CSV-1',
            'name' => 'Export Me',
            'type' => 'Individual',
            'is_active' => true,
        ]);
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUS-CSV-2',
            'name' => 'Skip Me',
            'type' => 'Company',
            'is_active' => false,
        ]);
        Customer::factory()->create([
            'company_id' => $this->otherCompany->id,
            'code' => 'CUS-CSV-OTHER',
            'name' => 'Export Me',
            'type' => 'Individual',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.customers.export', [
                'format' => 'csv',
                'status' => 'active',
                'search' => 'Export Me',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('CUS-CSV-1', $content);
        $this->assertStringNotContainsString('CUS-CSV-2', $content);
        $this->assertStringNotContainsString('CUS-CSV-OTHER', $content);
    }

    public function test_export_pdf_returns_pdf(): void
    {
        Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'CUS-PDF-1',
            'name' => 'Pdf Customer',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.customers.export', ['format' => 'pdf']));

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
        $role = Role::firstOrCreate(['name' => 'no-export', 'guard_name' => 'web']);
        $role->syncPermissions(['customer.view']);
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.customers.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
