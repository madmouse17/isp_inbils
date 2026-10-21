<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\BuildsSubscriptionBilling;
use Tests\Traits\CreatesCompanyUser;

class ReceivablesTest extends TestCase
{
    use BuildsSubscriptionBilling;
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_buckets_computed_per_customer(): void
    {
        $this->actingAs($this->createCompanyUser());
        $customer = Customer::factory()->create(['name' => 'Budi']);

        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'sent', 'due_date' => now()->addDays(5), 'total' => 100000, 'paid_amount' => 0]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'overdue', 'due_date' => now()->subDays(10), 'total' => 200000, 'paid_amount' => 0]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'partial', 'due_date' => now()->subDays(45), 'total' => 250000, 'paid_amount' => 100000]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'overdue', 'due_date' => now()->subDays(100), 'total' => 50000, 'paid_amount' => 0]);
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'paid', 'due_date' => now()->subDays(10), 'total' => 99999, 'paid_amount' => 99999]);

        $rows = BillingService::receivables();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Budi', $row['customer']);
        $this->assertEquals(100000.0, $row['current']);
        $this->assertEquals(200000.0, $row['b1_30']);
        $this->assertEquals(150000.0, $row['b31_60']);
        $this->assertEquals(0.0, $row['b61_90']);
        $this->assertEquals(50000.0, $row['b90_plus']);
        $this->assertEquals(500000.0, $row['total']);
        $this->assertSame(4, $row['invoice_count']);
    }

    public function test_rows_name_the_customer_and_its_subscriptions_by_public_id(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $subscription = $this->subscriptionIn($user->company);
        $this->invoiceFor($subscription, 5);

        $row = BillingService::receivables()[0];

        $this->assertSame($subscription->customer->public_id, $row['customer_id']);
        $this->assertSame([$subscription->public_id], array_column($row['subscriptions'], 'id'));
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Billing/Receivables'));
    }

    public function test_page_is_company_scoped_and_paginated(): void
    {
        $user = $this->createCompanyUser();
        $companyId = (int) $user->company_id;
        $otherCompany = Company::factory()->create(['is_active' => true]);

        foreach (range(1, 11) as $number) {
            $customer = Customer::factory()->create([
                'company_id' => $companyId,
                'name' => sprintf('Customer %02d', $number),
            ]);
            Invoice::factory()->create([
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'number' => sprintf('INV-OWN-%02d', $number),
                'status' => 'sent',
                'due_date' => now()->addDays(5)->toDateString(),
                'total' => 100000,
                'paid_amount' => 0,
            ]);
        }

        $otherCustomer = Customer::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Other company customer',
        ]);
        Invoice::factory()->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'status' => 'sent',
            'due_date' => now()->addDays(5)->toDateString(),
            'total' => 900000,
            'paid_amount' => 0,
        ]);

        $this->actingAs($user)
            ->get(route('admin.billing.receivables', ['page' => 2, 'per_page' => 10]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing/Receivables')
                ->has('rows.data', 1)
                ->where('rows.meta.current_page', 2)
                ->where('rows.meta.last_page', 2)
                ->where('rows.meta.per_page', 10)
                ->where('rows.meta.total', 11)
                ->where('rows.data.0.customer', 'Customer 11')
            );
    }

    public function test_company_totals_remain_full_dataset_totals_on_every_page(): void
    {
        $user = $this->createCompanyUser();
        foreach (range(1, 11) as $number) {
            $customer = Customer::factory()->create([
                'company_id' => $user->company_id,
                'name' => sprintf('Customer %02d', $number),
            ]);
            Invoice::factory()->create([
                'company_id' => $user->company_id,
                'customer_id' => $customer->id,
                'status' => 'sent',
                'due_date' => now()->addDays(5)->toDateString(),
                'total' => 100000,
                'paid_amount' => 0,
            ]);
        }

        foreach ([1, 2] as $pageNumber) {
            $this->actingAs($user)
                ->get(route('admin.billing.receivables', ['page' => $pageNumber, 'per_page' => 10]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('totals.total', '1100000.0000000000')
                    ->where('totals.current', '1100000.0000000000')
                    ->where('totals.invoice_count', 11)
                );
        }
    }

    public function test_sorting_uses_supported_columns_and_invalid_columns_fall_back_safely(): void
    {
        $user = $this->createCompanyUser();
        foreach ([['Alpha', 50000], ['Omega', 500000]] as [$name, $total]) {
            $customer = Customer::factory()->create(['company_id' => $user->company_id, 'name' => $name]);
            Invoice::factory()->create([
                'company_id' => $user->company_id,
                'customer_id' => $customer->id,
                'status' => 'sent',
                'due_date' => now()->addDays(5)->toDateString(),
                'total' => $total,
                'paid_amount' => 0,
            ]);
        }

        $this->actingAs($user)
            ->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.data.0.customer', 'Alpha')
                ->where('rows.data.1.customer', 'Omega')
            );

        $this->actingAs($user)
            ->get(route('admin.billing.receivables', ['sort_by' => 'total', 'sort_dir' => 'desc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.data.0.customer', 'Omega')
                ->where('rows.data.1.customer', 'Alpha')
            );

        $this->get(route('admin.billing.receivables', ['sort_by' => 'total', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.data.0.customer', 'Alpha')
                ->where('rows.data.1.customer', 'Omega')
            );

        $this->get(route('admin.billing.receivables', ['sort_by' => 'missing_column;--', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('rows.data.0.customer', 'Alpha')
                ->where('rows.data.1.customer', 'Omega')
            );
    }

    public function test_sort_and_page_size_are_preserved_in_filters_and_pagination_links(): void
    {
        $user = $this->createCompanyUser();
        foreach (range(1, 11) as $number) {
            $customer = Customer::factory()->create([
                'company_id' => $user->company_id,
                'name' => sprintf('Customer %02d', $number),
            ]);
            Invoice::factory()->create([
                'company_id' => $user->company_id,
                'customer_id' => $customer->id,
                'status' => 'sent',
                'due_date' => now()->addDays(5)->toDateString(),
                'total' => 100000,
                'paid_amount' => 0,
            ]);
        }

        $query = ['sort_by' => 'customer', 'sort_dir' => 'desc', 'per_page' => 10];
        $this->actingAs($user)
            ->get(route('admin.billing.receivables', $query))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort_by', 'customer')
                ->where('filters.sort_dir', 'desc')
                ->where('filters.per_page', 10)
                ->where('rows.data.0.customer', 'Customer 11')
                ->where('rows.links.next', function ($url) use ($query): bool {
                    parse_str(parse_url($url, PHP_URL_QUERY), $parameters);

                    return $parameters == array_merge($query, ['page' => 2]);
                })
            );
    }

    public function test_page_is_forbidden_without_billing_view_permission(): void
    {
        $admin = $this->createCompanyUser();
        $user = User::factory()->create([
            'company_id' => $admin->company_id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->grantPermissions($user, ['app.web']);

        $this->actingAs($user)
            ->get(route('admin.billing.receivables'))
            ->assertForbidden();
    }

    public function test_the_export_is_there_for_billing_export_holders_and_empty_without_open_invoices(): void
    {
        $response = $this->actingAs($this->createCompanyUser())->get(route('admin.billing.receivables.export'))->assertOk();

        $this->assertSame(
            ['Nomor tagihan', 'Pelanggan', 'Kode pelanggan', 'Tanggal terbit', 'Jatuh tempo', 'Lewat jatuh tempo (hari)', 'Umur', 'Total', 'Terbayar', 'Sisa', 'Status'],
            str_getcsv(trim(ltrim($response->streamedContent(), "\xEF\xBB\xBF"))),
        );
    }

    public function test_the_page_names_which_list_each_filter_belongs_to_and_keeps_the_customer_lists_own_words(): void
    {
        $user = $this->createCompanyUser();
        $customer = Customer::factory()->create(['company_id' => $user->company_id, 'name' => 'Budi']);
        Invoice::factory()->create(['company_id' => $user->company_id, 'customer_id' => $customer->id, 'status' => 'sent', 'due_date' => now()->addDays(5)->toDateString(), 'total' => 100000, 'paid_amount' => 0]);

        $this->actingAs($user)
            ->get(route('admin.billing.receivables', ['sort_by' => 'total', 'sort_dir' => 'desc', 'per_page' => 25, 'bucket' => 'current']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['sort_by' => 'total', 'sort_dir' => 'desc', 'per_page' => 25])
                ->where('invoiceFilters.bucket', 'current')
                ->has('rows.data', 1)
                ->has('invoices.data', 1));
    }
}
