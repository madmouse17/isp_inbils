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

    public function test_export_csv_writes_one_cell_per_labelled_column(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Column Customer',
        ]);
        $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-COLUMNS-1');

        $content = $this->actingAs($this->admin)
            ->get(route('admin.invoices.export', ['format' => 'csv', 'search' => 'INV-COLUMNS-1']))
            ->assertOk()
            ->streamedContent();

        $lines = preg_split('/\r?\n/', trim(ltrim($content, "\xEF\xBB\xBF")));
        $this->assertSame(
            ['Nomor tagihan', 'Pelanggan', 'Status', 'Total', 'Tanggal terbit', 'Jatuh tempo', 'Dibuat'],
            str_getcsv($lines[0]),
        );
        $row = str_getcsv($lines[1]);
        $this->assertCount(7, $row);
        $this->assertSame('INV-COLUMNS-1', $row[0]);
        $this->assertSame('Column Customer', $row[1]);
        $this->assertSame('draft', $row[2]);
    }

    public function test_index_honors_page_size_and_preserves_filters_across_sorted_boundaries(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        foreach (range(1, 26) as $number) {
            $this->makeInvoice((int) $this->admin->company_id, $customer, sprintf('INV-PAGE-%02d', $number))
                ->update(['issue_date' => '2026-09-15']);
        }
        $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-OUTSIDE')
            ->update(['issue_date' => '2026-09-15']);
        $filters = [
            'search' => 'INV-PAGE', 'status' => 'draft', 'source' => 'manual',
            'customer_id' => $customer->public_id, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
            'sort_by' => 'number', 'sort_dir' => 'desc', 'per_page' => 25,
        ];

        $this->actingAs($this->admin)->get(route('admin.invoices.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 25)
            ->where('invoices.meta.total', 26)
            ->where('invoices.meta.per_page', 25)
            ->where('invoices.data.0.number', 'INV-PAGE-26')
            ->where('invoices.data.24.number', 'INV-PAGE-02')
            ->where('invoices.links.next', function ($url) use ($filters): bool {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);

                return $query == array_merge($filters, ['page' => 2]);
            })
            );
        $this->get(route('admin.invoices.index', array_merge($filters, ['page' => 2])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.number', 'INV-PAGE-01')
            ->where('invoices.meta.current_page', 2)
            ->where('filters.date_from', '2026-09-01')
            ->where('filters.date_to', '2026-09-30')
            );
        $this->get(route('admin.invoices.index', array_merge($filters, ['per_page' => 7])))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 10)
            ->where('invoices.meta.per_page', 10)
            ->where('invoices.data.9.number', 'INV-PAGE-17')
            );
    }

    public function test_index_and_export_share_filters_with_independent_distractors(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Contract Customer']);
        $anotherCustomer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $foreignCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);
        $attributes = [
            'company_id' => $this->admin->company_id,
            'customer_id' => $customer->id,
            'type' => 'one_time',
            'source' => 'manual',
            'status' => 'draft',
            'issue_date' => '2026-09-15',
        ];
        $matches = [];
        foreach (['2026-09-01', '2026-09-30'] as $index => $date) {
            $matches[] = Invoice::factory()->create(array_merge($attributes, [
                'number' => 'INV-MATCH-'.($index + 1), 'issue_date' => $date,
            ]));
        }
        $distractors = [
            ['source' => 'subscription'],
            ['customer_id' => $anotherCustomer->id],
            ['status' => 'paid'],
            ['issue_date' => '2026-08-31'],
            ['issue_date' => '2026-10-01'],
            ['type' => 'recurring'],
            ['number' => 'INV-UNRELATED'],
            ['company_id' => $this->otherCompany->id, 'customer_id' => $foreignCustomer->id],
        ];
        $excluded = [];
        foreach ($distractors as $index => $overrides) {
            $excluded[] = Invoice::factory()->create(array_merge($attributes, [
                'number' => 'INV-MATCH-SKIP-'.$index,
            ], $overrides));
        }
        $filters = [
            'search' => 'INV-MATCH', 'type' => 'one_time', 'source' => 'manual',
            'customer_id' => $customer->public_id, 'status' => 'draft',
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30',
            'sort_by' => 'number', 'sort_dir' => 'desc', 'per_page' => 25,
        ];

        $this->actingAs($this->admin)->get(route('admin.invoices.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 2)
            ->where('invoices.data.0.id', $matches[1]->public_id)
            ->where('invoices.data.1.id', $matches[0]->public_id)
            ->where('invoices.meta.per_page', 25)
            ->where('filters.date_from', '2026-09-01')
            ->where('filters.date_to', '2026-09-30')
            ->where('filters.search', 'INV-MATCH')
            ->where('filters.source', 'manual')
            ->where('filters.status', 'draft')
            ->where('filters.customer_id', $customer->public_id)
            ->where('filters.sort_by', 'number')
            ->where('filters.sort_dir', 'desc')
            );
        $response = $this->get(route('admin.invoices.export', array_merge($filters, ['format' => 'csv'])))->assertOk();
        $content = $response->streamedContent();
        foreach ($matches as $invoice) {
            $this->assertStringContainsString($invoice->number, $content);
        }
        foreach ($excluded as $invoice) {
            $this->assertStringNotContainsString($invoice->number, $content);
        }
        $this->assertLessThan(strpos($content, $matches[0]->number), strpos($content, $matches[1]->number));
    }

    public function test_customer_search_matches_index_and_export_without_bypassing_status_or_tenant(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Needle Customer']);
        $otherCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id, 'name' => 'Needle Foreign']);
        $match = $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-CUSTOMER-HIT');
        $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-CUSTOMER-PAID', 'paid');
        $this->makeInvoice((int) $this->otherCompany->id, $otherCustomer, 'INV-CUSTOMER-FOREIGN');
        $filters = ['search' => 'Needle', 'status' => 'draft'];

        $this->actingAs($this->admin)->get(route('admin.invoices.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 1)->where('invoices.data.0.id', $match->public_id)
            );
        $content = $this->get(route('admin.invoices.export', array_merge($filters, ['format' => 'csv'])))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-CUSTOMER-HIT', $content);
        $this->assertStringNotContainsString('INV-CUSTOMER-PAID', $content);
        $this->assertStringNotContainsString('INV-CUSTOMER-FOREIGN', $content);
    }

    public function test_customer_filter_with_a_foreign_customer_id_never_returns_foreign_rows(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $foreignCustomer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);
        $this->makeInvoice((int) $this->admin->company_id, $customer, 'INV-OWN-FILTER');
        $this->makeInvoice((int) $this->otherCompany->id, $foreignCustomer, 'INV-FOREIGN-FILTER');
        $this->assertDatabaseHas('invoices', ['number' => 'INV-FOREIGN-FILTER', 'customer_id' => $foreignCustomer->id]);
        $filters = ['customer_id' => $foreignCustomer->public_id];

        $this->actingAs($this->admin)->get(route('admin.invoices.index', $filters))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('invoices.data', 0)->where('invoices.meta.total', 0)
            );
        $content = $this->get(route('admin.invoices.export', [...$filters, 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('INV-FOREIGN-FILTER', $content);
        $this->assertStringNotContainsString('INV-OWN-FILTER', $content);
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
