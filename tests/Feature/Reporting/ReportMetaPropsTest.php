<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Tests\TestCase;

class ReportMetaPropsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    public function test_business_page_exposes_a_v1_definition_for_every_payload_key(): void
    {
        $response = $this->get(route('admin.reports.business'))->assertOk();

        $this->assertMetaCoversPayload($response->inertiaProps(), 'business', 'business.v1', 'data');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/Business')
            ->where('filters', [])
            ->has('data.mrr')
            ->has('data.asset_distribution'));
    }

    public function test_sla_page_exposes_a_v1_definition_for_every_payload_key(): void
    {
        $response = $this->get(route('admin.reports.sla', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']))
            ->assertOk();

        $this->assertMetaCoversPayload($response->inertiaProps(), 'sla', 'sla.v1', 'data');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/Sla')
            ->where('filters.date_from', '2026-01-01')
            ->where('filters.date_to', '2026-01-31')
            ->where('data.total_resolved', 0)
            ->where('data.by_category', []));
    }

    public function test_asset_page_exposes_a_v1_definition_for_every_payload_key(): void
    {
        $response = $this->get(route('admin.reports.asset'))->assertOk();

        $this->assertMetaCoversPayload($response->inertiaProps(), 'asset', 'asset.v1', 'data');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/Asset')
            ->where('data.installation_count', 0)
            ->where('data.retired_count', 0));
    }

    public function test_sales_page_exposes_a_v1_definition_for_every_metric(): void
    {
        $response = $this->get(route('admin.reports.sales'))->assertOk();

        $props = $response->inertiaProps();
        $registryKeys = array_map(
            fn ($metric): string => $metric->key,
            MetricRegistry::forReport(ReportType::Sales),
        );

        $this->assertCount(7, $props['meta']['metrics']);
        $this->assertSame($registryKeys, array_keys($props['meta']['metrics']));
        $this->assertMetaCoversPayload(
            ['meta' => $props['meta'], 'totals' => $props['data']['totals']],
            'sales',
            'sales.v1',
            'totals',
        );
        $response->assertInertia(fn (Assert $page) => $page->component('Admin/Reports/Sales'));
    }

    public function test_technician_page_exposes_meta_without_a_selected_technician(): void
    {
        $response = $this->get(route('admin.reports.technician'))->assertOk();

        $props = $response->inertiaProps();
        $this->assertNull($props['data']);
        $this->assertSame('technician', $props['meta']['report']);
        $this->assertSame('technician.v1', $props['meta']['formula_version']);
        $this->assertCount(11, $props['meta']['metrics']);
        foreach ($props['meta']['metrics'] as $key => $definition) {
            $this->assertSame($key, $definition['key']);
            $this->assertSame('v1', $definition['version']);
        }
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/Technician')
            ->has('technicians.data', 0)
            ->where('filters', []));
    }

    public function test_stock_card_page_exposes_meta_and_keeps_movements_and_filters(): void
    {
        $product = $this->productForCompany($this->admin->company_id);

        $response = $this->get(route('admin.reports.stock-card', ['product_id' => $product->id]))->assertOk();

        $props = $response->inertiaProps();
        $this->assertSame('stock_card', $props['meta']['report']);
        $this->assertSame('stock_card.v1', $props['meta']['formula_version']);
        $this->assertSame(['movements', 'quantity', 'balance_after'], array_keys($props['meta']['metrics']));
        foreach ($props['meta']['metrics'] as $definition) {
            $this->assertSame('v1', $definition['version']);
        }
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Reports/StockCard')
            ->where('filters.product_id', (string) $product->id)
            ->where('filters.per_page', 10)
            ->where('movements.meta.total', 0)
            ->has('movements.data', 0));
    }

    public function test_stock_card_page_without_a_product_keeps_null_movements(): void
    {
        $this->get(route('admin.reports.stock-card'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('movements', null)
                ->where('meta.formula_version', 'stock_card.v1'));
    }

    public function test_audit_log_page_has_no_meta(): void
    {
        $this->get(route('admin.reports.audit-log'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Reports/AuditLog')
                ->missing('meta'));
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function assertMetaCoversPayload(array $props, string $report, string $formulaVersion, string $payload): void
    {
        $this->assertSame($report, $props['meta']['report']);
        $this->assertSame($formulaVersion, $props['meta']['formula_version']);

        $payloadKeys = array_keys($props[$payload]);
        $this->assertNotEmpty($payloadKeys);
        foreach ($payloadKeys as $key) {
            $this->assertArrayHasKey($key, $props['meta']['metrics'], "No definition for payload key [{$key}]");
            $this->assertSame('v1', $props['meta']['metrics'][$key]['version'], $key);
            $this->assertSame($key, $props['meta']['metrics'][$key]['key']);
            $this->assertSame($report, $props['meta']['metrics'][$key]['report']);
        }
        $this->assertEqualsCanonicalizing($payloadKeys, array_keys($props['meta']['metrics']));
    }

    private function productForCompany(int $companyId): Product
    {
        $unit = Unit::factory()->create(['company_id' => $companyId]);
        $category = Category::factory()->create([
            'company_id' => $companyId,
            'unit_id' => $unit->id,
        ]);

        return Product::factory()->create([
            'company_id' => $companyId,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
        ]);
    }
}
