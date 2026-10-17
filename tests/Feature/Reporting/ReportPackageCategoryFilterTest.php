<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Customer;
use App\Models\Core\CustomerAddress;
use App\Models\Core\ServiceSubscription;
use App\Models\User;
use App\Support\PublicId;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\Payment;
use Modules\Service\Models\ServicePackage;
use Modules\Service\Models\ServicePackageCategory;
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;
use Tests\Traits\WithoutAppOnlyRedirect;

/**
 * The "Kategori paket" filter of the Sales KPI and Business reports: subscriptions and money are limited to the
 * packages of one category. Expected figures are worked out by hand from the fixtures below.
 *
 * Rumahan package: subscription 100000 (active), paid invoice 100000 (recurring) with a payment of 100000.
 * Bisnis package: subscription 500000 (active), sent invoice 500000 (recurring), nothing paid.
 * Besides: a paid one-time invoice of 30000 with no subscription, paid in full.
 */
class ReportPackageCategoryFilterTest extends TestCase
{
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;
    use WithoutAppOnlyRedirect;

    private const PERIOD = ['date_from' => '2026-03-01', 'date_to' => '2026-03-31'];

    private User $admin;

    private int $companyId;

    private ServicePackageCategory $rumahan;

    private ServicePackageCategory $bisnis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->rumahan = $this->category('RUMAHAN', 1, $this->companyId);
        $this->bisnis = $this->category('BISNIS', 2, $this->companyId);

        $customer = Customer::factory()->create(['company_id' => $this->companyId, 'acquired_by_user_id' => $this->admin->id, 'created_at' => '2026-03-01 08:00:00']);
        $address = CustomerAddress::factory()->create(['customer_id' => $customer->id]);
        $home = ServicePackage::factory()->create(['company_id' => $this->companyId, 'service_package_category_id' => $this->rumahan->id]);
        $biz = ServicePackage::factory()->create(['company_id' => $this->companyId, 'service_package_category_id' => $this->bisnis->id]);

        $homeSub = $this->subscription($customer, $address, $home, 100000);
        $bizSub = $this->subscription($customer, $address, $biz, 500000);
        $this->invoice($customer, $homeSub, 'paid', 100000, 100000);
        $this->invoice($customer, $bizSub, 'sent', 500000, 0);
        $this->invoice($customer, null, 'paid', 30000, 30000, 'one_time');
    }

    private function category(string $code, int $sortOrder, int $companyId): ServicePackageCategory
    {
        return ServicePackageCategory::factory()->create(['company_id' => $companyId, 'code' => $code, 'name' => ucfirst(strtolower($code)), 'sort_order' => $sortOrder]);
    }

    private function subscription(Customer $customer, CustomerAddress $address, ServicePackage $package, int $mrc): ServiceSubscription
    {
        return ServiceSubscription::factory()->create([
            'company_id' => $this->companyId, 'customer_id' => $customer->id, 'service_package_id' => $package->id,
            'installation_address_id' => $address->id, 'status' => 'active', 'activation_date' => '2026-03-02',
            'created_at' => '2026-03-02 09:00:00', 'mrc_amount' => $mrc,
        ]);
    }

    private function invoice(Customer $customer, ?ServiceSubscription $subscription, string $status, int $total, int $paid, string $type = 'recurring'): Invoice
    {
        $invoice = Invoice::factory()->create([
            'company_id' => $this->companyId, 'customer_id' => $customer->id, 'subscription_id' => $subscription?->id,
            'created_by' => $this->admin->id, 'type' => $type, 'status' => $status, 'issue_date' => '2026-03-10',
            'due_date' => '2026-03-24', 'total' => $total, 'paid_amount' => $paid,
        ]);

        if ($paid > 0) {
            Payment::query()->create([
                'company_id' => $this->companyId, 'invoice_id' => $invoice->id, 'amount' => $paid, 'method' => 'transfer',
                'paid_at' => '2026-03-12 10:00:00', 'received_by' => $this->admin->id,
            ]);
        }

        return $invoice;
    }

    /** @return array<string, int|string> */
    private function salesTotals(array $query): array
    {
        $response = $this->actingAs($this->admin)->get(route('admin.reports.sales', [...self::PERIOD, ...$query]))->assertOk();
        $totals = $response->inertiaProps('data.totals');

        return array_intersect_key($totals, array_flip(['customers_acquired', 'subscriptions_activated', 'active_mrr', 'invoiced', 'collected', 'outstanding']));
    }

    public function test_sales_report_limits_subscriptions_and_money_to_the_chosen_category(): void
    {
        $all = ['customers_acquired' => 1, 'subscriptions_activated' => 2, 'active_mrr' => '600000.00', 'invoiced' => '630000.00', 'collected' => '130000.00', 'outstanding' => '500000.00'];

        $this->assertSame($all, $this->salesTotals([]));
        $this->assertSame(
            ['customers_acquired' => 1, 'subscriptions_activated' => 1, 'active_mrr' => '100000.00', 'invoiced' => '100000.00', 'collected' => '100000.00', 'outstanding' => '0.00'],
            $this->salesTotals(['service_package_category_id' => $this->rumahan->public_id]),
        );
        $this->assertSame(
            ['customers_acquired' => 1, 'subscriptions_activated' => 1, 'active_mrr' => '500000.00', 'invoiced' => '500000.00', 'collected' => '0.00', 'outstanding' => '500000.00'],
            $this->salesTotals(['service_package_category_id' => $this->bisnis->public_id]),
        );
    }

    public function test_sales_report_carries_the_category_options_and_the_applied_filter(): void
    {
        $foreign = $this->category('ASING', 1, (int) $this->userWithRole('admin')->company_id);

        $this->actingAs($this->admin)->get(route('admin.reports.sales', ['service_package_category_id' => $this->bisnis->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('packageCategories', [
                    ['id' => $this->rumahan->public_id, 'code' => 'RUMAHAN', 'name' => 'Rumahan', 'is_active' => true],
                    ['id' => $this->bisnis->public_id, 'code' => 'BISNIS', 'name' => 'Bisnis', 'is_active' => true],
                ])
                ->where('filters.service_package_category_id', $this->bisnis->public_id));

        $this->assertNotContains($foreign->public_id, collect($this->actingAs($this->admin)->get(route('admin.reports.sales'))->inertiaProps('packageCategories'))->pluck('id')->all());
    }

    public function test_business_report_limits_subscription_and_invoice_figures_to_the_chosen_category(): void
    {
        $figures = fn (array $query) => collect($this->actingAs($this->admin)->get(route('admin.reports.business', [...self::PERIOD, ...$query]))->assertOk()->inertiaProps('data'))
            ->only(['mrr', 'active_subscriptions', 'new_subscriptions', 'revenue_paid', 'recurring_revenue', 'one_time_revenue', 'outstanding'])
            ->map(fn ($value) => (float) $value)->all();

        $this->assertSame(
            ['mrr' => 600000.0, 'active_subscriptions' => 2.0, 'new_subscriptions' => 2.0, 'revenue_paid' => 130000.0, 'recurring_revenue' => 100000.0, 'one_time_revenue' => 30000.0, 'outstanding' => 500000.0],
            $figures([]),
        );
        $this->assertSame(
            ['mrr' => 100000.0, 'active_subscriptions' => 1.0, 'new_subscriptions' => 1.0, 'revenue_paid' => 100000.0, 'recurring_revenue' => 100000.0, 'one_time_revenue' => 0.0, 'outstanding' => 0.0],
            $figures(['service_package_category_id' => $this->rumahan->public_id]),
        );
        $this->assertSame(
            ['mrr' => 500000.0, 'active_subscriptions' => 1.0, 'new_subscriptions' => 1.0, 'revenue_paid' => 0.0, 'recurring_revenue' => 0.0, 'one_time_revenue' => 0.0, 'outstanding' => 500000.0],
            $figures(['service_package_category_id' => $this->bisnis->public_id]),
        );
    }

    public function test_business_page_props_hold_the_options_the_filter_and_drill_links_that_keep_it(): void
    {
        $this->actingAs($this->admin)->get(route('admin.reports.business', [...self::PERIOD, 'service_package_category_id' => $this->bisnis->public_id]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('packageCategories.0.code', 'RUMAHAN')
                ->where('packageCategories.1.code', 'BISNIS')
                ->where('filters.service_package_category_id', $this->bisnis->public_id)
                ->where('drill.mrr', fn (string $url) => str_contains($url, 'service_package_category_id='.$this->bisnis->public_id)));
    }

    public function test_drill_lists_and_their_tie_out_follow_the_filter(): void
    {
        $this->actingAs($this->admin);
        $query = [...self::PERIOD, 'service_package_category_id' => $this->bisnis->public_id];

        $mrr = $this->get(route('admin.reports.business.drill', ['metric' => 'mrr', ...$query]))->assertOk();
        $this->assertSame(1, $mrr->inertiaProps('rows.total'));
        $this->assertSame('500000.00', $mrr->inertiaProps('tie_out.sum'));
        $this->assertTrue($mrr->inertiaProps('tie_out.matches'));

        $outstanding = $this->get(route('admin.reports.business.drill', ['metric' => 'outstanding', ...$query]))->assertOk();
        $this->assertSame(1, $outstanding->inertiaProps('rows.total'));
        $this->assertSame('500000.00', $outstanding->inertiaProps('tie_out.sum'));

        $paid = $this->get(route('admin.reports.business.drill', ['metric' => 'revenue_paid', ...$query]))->assertOk();
        $this->assertSame(0, $paid->inertiaProps('rows.total'));
    }

    public function test_export_states_the_filter_and_which_figures_it_does_not_limit(): void
    {
        $rows = $this->csvRecords($this->actingAs($this->admin)->get(route('admin.reports.business.export', [...self::PERIOD, 'service_package_category_id' => $this->rumahan->public_id])));
        $byMetric = collect($rows)->keyBy('Metrik');

        $this->assertStringContainsString('service_package_category_id='.$this->rumahan->public_id, $byMetric['MRR']['Filter']);
        $this->assertStringNotContainsString('kategori paket', $byMetric['MRR']['Cakupan']);
        $this->assertStringContainsString('Filter kategori paket tidak berlaku', $byMetric['Kepatuhan SLA']['Cakupan']);
    }

    public function test_the_category_must_belong_to_the_company(): void
    {
        $foreign = $this->category('ASING', 1, (int) $this->userWithRole('admin')->company_id);

        foreach (['admin.reports.sales', 'admin.reports.business'] as $route) {
            foreach ([$foreign->public_id, PublicId::new(), (string) $this->bisnis->id, '999999'] as $categoryId) {
                $this->actingAs($this->admin)->getJson(route($route, ['service_package_category_id' => $categoryId]))
                    ->assertUnprocessable()->assertJsonValidationErrors('service_package_category_id');
            }
        }
    }
}
