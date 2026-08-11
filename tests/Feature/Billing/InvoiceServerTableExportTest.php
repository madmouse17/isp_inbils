<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceServerTableExportTest extends TestCase
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

    private function makeInvoice(int $companyId, Customer $customer, string $number, string $status = 'draft'): Invoice
    {
        return Invoice::factory()->create([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'number' => $number,
            'type' => 'one_time',
            'source' => 'manual',
            'status' => $status,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
        ]);
    }

    public function test_index_is_company_scoped_and_paginated(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);

        foreach (range(1, 11) as $number) {
            $this->makeInvoice((int) $this->admin->company_id, $customer, "INV-OWN-{$number}");
        }

        $this->makeInvoice((int) $this->otherCompany->id, $otherCustomer, 'INV-OTHER-1');

        $this->actingAs($this->admin)
            ->get(route('admin.invoices.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Invoices/Index')
                ->has('invoices.data', 1)
                ->where('invoices.meta.current_page', 2)
                ->where('invoices.meta.last_page', 2)
                ->where('can.export', true)
            );
    }

    public function test_export_csv_respects_filters_and_company_scope(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $otherCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-CSV-1');
        $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-CSV-2', 'sent');
        $this->makeInvoice((int) $this->otherCompany->id, $otherCustomer, 'INV-CSV-OTHER');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.invoices.export', [
                'format' => 'csv',
                'search' => 'INV-CSV-1',
                'status' => 'draft',
                'source' => 'manual',
            ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('INV-CSV-1', $content);
        $this->assertStringNotContainsString('INV-CSV-2', $content);
        $this->assertStringNotContainsString('INV-CSV-OTHER', $content);
    }

    public function test_export_forbidden_without_permission(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => 'billing-view-only', 'guard_name' => 'web']);
        Permission::findOrCreate('billing.view', 'web');
        $role->syncPermissions(['billing.view']);
        Permission::query()->where('name', 'billing.export')->delete();
        $user->assignRole($role);

        $this->actingAs($user)
            ->get(route('admin.invoices.export', ['format' => 'csv']))
            ->assertForbidden();
    }
}
