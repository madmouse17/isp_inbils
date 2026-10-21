<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\AuditService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Unit;
use Modules\Reporting\Exports\ExportLabels;
use Modules\Reporting\Registry\MetricUnit;
use Modules\Service\Models\ServicePackageCategory;
use Modules\SPK\Database\Factories\WorkOrderFactory;
use Modules\SPK\Models\WorkOrderItem;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * F9-D02: a report file speaks the screen's words. The status and type of a row, the unit of a figure, the module of an
 * audit entry and what a stock movement points at are words (never `on_progress`, `receive`, `currency` or a class and
 * an id); the filters are named by name; the PDF of the audit log holds no id.
 */
class ExportLabelsTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    private const ULID = '/\b[0-9A-HJKMNP-TV-Z]{26}\b/i';

    private User $admin;

    private int $companyId;

    /** @var array<string, mixed> What the last PDF handed to the exports.table view. */
    private array $view = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'UTC']);
        $this->actingAs($this->admin);

        View::composer('exports.table', function ($view): void {
            $this->view = $view->getData();
        });
    }

    public function test_the_words_are_the_words_of_the_screen(): void
    {
        $support = [];
        preg_match("/support: \{ label: '([^']+)'/", (string) file_get_contents(base_path('resources/js/Pages/Admin/Reports/AuditLog.tsx')), $support);

        $this->assertEquals([...$this->screenMap('audit', 'AUDIT_MODULE'), 'support' => $support[1]], ExportLabels::MODULES);
        $this->assertEquals($this->screenMap('inventory', 'STOCK_MOVEMENT_TYPE'), ExportLabels::MOVEMENT_TYPES);
        $this->assertEquals($this->screenMap('network', 'NETWORK_ASSET_STATUS'), ExportLabels::ASSET_STATUS);
        $this->assertEquals($this->screenMap('network', 'NETWORK_ASSET_TYPE'), ExportLabels::ASSET_TYPE);
        $this->assertEquals($this->screenMap('subscription', 'SUBSCRIPTION_STATUS'), ExportLabels::SUBSCRIPTION_STATUS);
        $this->assertEquals($this->screenMap('invoice', 'INVOICE_STATUS'), ExportLabels::INVOICE_STATUS);
        $this->assertEquals($this->screenMap('invoice', 'INVOICE_TYPE'), ExportLabels::INVOICE_TYPE);
        $this->assertEquals($this->screenMap('ticket', 'TICKET_STATUS'), ExportLabels::TICKET_STATUS);
        $this->assertEquals($this->screenMap('workOrder', 'WORK_ORDER_STATUS'), ExportLabels::WORK_ORDER_STATUS);
        $this->assertEquals($this->screenMap('workOrder', 'WORK_ORDER_TYPE'), ExportLabels::WORK_ORDER_TYPE);
        $this->assertEquals($this->screenMap('common', 'PRIORITY'), ExportLabels::PRIORITY);
    }

    public function test_every_unit_has_a_word_and_a_value_no_map_lists_is_written_as_it_is(): void
    {
        foreach (MetricUnit::cases() as $unit) {
            $this->assertNotSame($unit->value, ExportLabels::unit($unit), $unit->value.' is written in words');
        }

        $this->assertSame('Rupiah', ExportLabels::unit(MetricUnit::Currency, 'IDR'));
        $this->assertSame('USD', ExportLabels::unit(MetricUnit::Currency, 'USD'));
        $this->assertSame('Umum', ExportLabels::module(null));
        $this->assertSame('baru_sekali', ExportLabels::module('baru_sekali'));
        $this->assertSame('Instalasi', ExportLabels::detail('spk_by_type', 'installation'));
        $this->assertSame('Support', ExportLabels::detail('tickets_by_category', 'Support'), 'a name stays a name');
    }

    public function test_the_summary_files_hold_words_where_they_held_codes(): void
    {
        $c = $this->companyId;
        $central = $this->worldLocation($c, 'POP-C', 'POP Central');
        $this->worldAsset($c, 'router', 'available', $central);
        $this->worldAsset($c, 'router', 'installed', $central, true);
        $this->worldAsset($c, 'switch', 'retired', $central);
        $this->worldSubscription($c, 'active', 100000, '2026-01-05 00:00:00');
        $technician = $this->technician('Teknisi Satu');
        $this->worldWorkOrder($c, 'installation', 'completed', '2026-01-10 10:00:00', $technician->id, '2026-01-10 08:00:00');
        $category = $this->worldCategory($c, 'Support', 'SUP');
        $this->worldTicket($c, $category, '2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 10:00:00', '2026-01-12 12:00:00', assignedTo: $technician->id);

        $business = $this->csvFigures($this->get(route('admin.reports.business.export', self::JANUARY)));
        $this->assertEquals(1, $business['Sebaran aset / Tersedia']);
        $this->assertEquals(1, $business['Sebaran aset / Terpasang']);
        $this->assertEquals(1, $business['Sebaran aset / Dipensiunkan']);

        $asset = $this->csvFigures($this->get(route('admin.reports.asset.export', self::JANUARY)));
        $this->assertEquals(1, $asset['Sebaran status / Tersedia']);
        $this->assertEquals(3, $asset['Aset per lokasi / POP Central']);

        $files = [
            'business' => $this->get(route('admin.reports.business.export', self::JANUARY)),
            'asset' => $this->get(route('admin.reports.asset.export', self::JANUARY)),
            'sla' => $this->get(route('admin.reports.sla.export', self::JANUARY)),
            'technician' => $this->get(route('admin.reports.technician.export', ['technician_id' => $technician->public_id, ...self::JANUARY])),
        ];
        $units = [];
        foreach ($files as $name => $response) {
            $records = $this->csvRecords($response);
            $this->assertNotEmpty($records, $name);
            array_push($units, ...array_column($records, 'Satuan'));
        }
        $this->assertEqualsCanonicalizing(['Rupiah', 'Jumlah', 'Persen', 'Menit', 'Nilai (1-5)'], array_values(array_unique($units)), 'every unit is a word');

        $figures = $this->csvFigures($files['technician']);
        $this->assertEquals(1, $figures['SPK per jenis / Instalasi']);
        $this->assertEquals(1, $figures['Tiket per kategori / Support']);
    }

    public function test_the_filter_column_names_the_record_it_filters_by_and_never_its_id(): void
    {
        $c = $this->companyId;
        $central = $this->worldLocation($c, 'POP-C', 'POP Central');
        $this->worldAsset($c, 'router', 'installed', $central);
        $technician = $this->technician('Teknisi Satu');
        $category = $this->worldCategory($c, 'Support', 'SUP');
        $package = ServicePackageCategory::query()->create(['company_id' => $c, 'code' => 'RMH', 'name' => 'Rumahan', 'sort_order' => 1, 'is_active' => true]);

        $filter = fn ($response): string => $this->csvRecords($response)[0]['Filter'];

        $technicianFilter = $filter($this->get(route('admin.reports.technician.export', ['technician_id' => $technician->public_id, ...self::JANUARY])));
        $this->assertSame('Teknisi: Teknisi Satu; Tanggal mulai: 2026-01-01; Tanggal akhir: 2026-01-31', $technicianFilter);

        $this->assertSame(
            'Lokasi: POP Central; Jenis aset: Router; Tanggal mulai: 2026-01-01; Tanggal akhir: 2026-01-31',
            $filter($this->get(route('admin.reports.asset.export', ['location_id' => $central->public_id, 'asset_type' => 'router', ...self::JANUARY]))),
        );
        $this->assertSame(
            'Tanggal mulai: 2026-01-01; Tanggal akhir: 2026-01-31; Kategori: Support',
            $filter($this->get(route('admin.reports.sla.export', [...self::JANUARY, 'category_id' => $category->public_id]))),
        );
        $this->assertSame(
            'Tanggal mulai: 2026-01-01; Tanggal akhir: 2026-01-31; Kategori paket: Rumahan',
            $filter($this->get(route('admin.reports.business.export', [...self::JANUARY, 'service_package_category_id' => $package->public_id]))),
        );
        $this->assertSame('tidak ada', $filter($this->get(route('admin.reports.business.export'))));

        // The PDF says the same in its header, with the period on its own line.
        $this->get(route('admin.reports.technician.export', ['format' => 'pdf', 'technician_id' => $technician->public_id, ...self::JANUARY]))->streamedContent();
        $this->assertSame(['Teknisi: Teknisi Satu'], $this->view['filters']);
        $this->assertSame('01 Jan 2026 sampai 31 Jan 2026', $this->view['period']);
    }

    public function test_the_stock_card_file_says_what_the_screen_says(): void
    {
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'Asia/Jakarta']);
        $product = $this->product();
        $location = Location::factory()->create(['company_id' => $this->companyId, 'name' => 'Gudang Utama']);
        $workOrder = WorkOrderFactory::new()->create(['company_id' => $this->companyId]);
        $item = WorkOrderItem::query()->create(['company_id' => $this->companyId, 'work_order_id' => $workOrder->id, 'product_id' => $product->id, 'quantity_used' => 2]);
        $this->movement($product, $location, 'issue', '2026-01-12 02:00:00', (new WorkOrderItem())->getMorphClass(), $item->id);
        $this->movement($product, $location, 'receive', '2026-01-12 03:00:00');
        $this->movement($product, $location, 'adjustment', '2026-01-12 04:00:00');
        $query = ['product_id' => $product->public_id, 'location_id' => $location->public_id, 'sort_by' => 'created_at', 'sort_dir' => 'asc'];

        $records = $this->csvRecords($this->get(route('admin.reports.stock-card.export', $query)));

        $this->assertSame(['Keluar', 'Masuk', 'Penyesuaian'], array_column($records, 'Jenis'));
        $this->assertSame('SPK '.$workOrder->code, $records[0]['Referensi'], 'the code a person knows the SPK by, not a class and an id');
        $this->assertSame('2026-01-12 09:00:00', $records[0]['Tanggal'], 'the company clock, like the page, not UTC');

        $this->get(route('admin.reports.stock-card.export', [...$query, 'format' => 'pdf', ...self::JANUARY]))->streamedContent();

        $this->assertSame(['Barang: '.$product->name, 'Lokasi: Gudang Utama'], $this->view['filters']);
        $this->assertSame('12 Jan 2026 09:00', $this->view['rows'][0]['Tanggal']);
        $this->assertSame(['Jumlah', 'Saldo setelah'], array_values(array_intersect(['Tanggal', 'Jumlah', 'Saldo setelah'], $this->view['figures'])), 'the quantities align right, the date does not');
    }

    public function test_the_audit_file_words_the_module_and_its_pdf_holds_no_id(): void
    {
        $location = Location::factory()->create(['company_id' => $this->companyId, 'name' => 'POP Barat', 'code' => 'POP-BRT']);
        AuditService::log('inventory_request', 'cancelled', ['code' => 'REQ-2026-00001']);
        AuditService::log('location', 'created', ['name' => 'POP Barat', 'code' => 'POP-BRT'], $location);

        $records = collect($this->csvRecords($this->get(route('admin.reports.audit-log.export', ['log_name' => 'location']))));

        $this->assertSame(['Lokasi'], $records->pluck('Modul')->unique()->values()->all());
        $this->assertSame($location->public_id, $records[0]['ID subjek'], 'the CSV keeps its ids for a spreadsheet');
        $this->assertSame('Permintaan barang', $this->csvRecords($this->get(route('admin.reports.audit-log.export', ['log_name' => 'inventory_request'])))[0]['Modul']);

        $this->get(route('admin.reports.audit-log.export', ['format' => 'pdf']))->streamedContent();

        $this->assertSame(['Waktu', 'Modul', 'Deskripsi', 'Pelaku', 'Subjek'], $this->view['headers']);
        $modules = array_column($this->view['rows'], 'Modul');
        $this->assertContains('Permintaan barang', $modules);
        $this->assertContains('Lokasi', $modules);
        $this->assertContains('POP-BRT', array_column($this->view['rows'], 'Subjek'));
        $this->assertDoesNotMatchRegularExpression(self::ULID, json_encode($this->view['rows'], JSON_UNESCAPED_UNICODE), 'no id, subject type or code in the PDF');
        $this->assertStringNotContainsString('Models\\', json_encode($this->view['rows'], JSON_UNESCAPED_UNICODE));
    }

    public function test_the_drill_file_has_statuses_and_types_in_words_and_the_pdf_figures(): void
    {
        $c = $this->companyId;
        $this->worldInvoice($c, 'recurring', 'paid', 1250000, 1250000, '2026-01-10');
        $this->worldTicket($c, $this->worldCategory($c, 'Support', 'SUP'), '2026-01-12 08:00:00', null, '2026-01-12 09:53:00', '2026-01-12 12:00:00', 'closed');

        $invoice = $this->csvRecords($this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', ...self::JANUARY])))[0];
        $this->assertSame('Berlangganan', $invoice['Jenis']);
        $this->assertSame('Lunas', $invoice['Status']);

        $ticket = $this->csvRecords($this->get(route('admin.reports.sla.drill.export', ['metric' => 'total_resolved', ...self::JANUARY])))[0];
        $this->assertSame('Ditutup', $ticket['Status']);

        $this->get(route('admin.reports.business.drill.export', ['metric' => 'revenue_paid', 'format' => 'pdf', ...self::JANUARY]))->streamedContent();

        $this->assertSame('Rp 1.250.000', $this->view['rows'][0]['Terbayar']);
        $this->assertSame('10 Jan 2026', $this->view['rows'][0]['Terbit']);
        $this->assertSame('Lunas', $this->view['rows'][0]['Status']);
        $this->assertSame('01 Jan 2026 sampai 31 Jan 2026', $this->view['period']);
    }

    public function test_the_tie_out_formula_of_a_drill_list_reads_in_words(): void
    {
        $c = $this->companyId;
        $this->worldInvoice($c, 'recurring', 'sent', 500000, 200000, '2026-01-10');
        $this->worldSubscription($c, 'active', 100000, '2026-01-05 00:00:00');

        $label = fn (string $metric): string => $this->get(route('admin.reports.business.drill', ['metric' => $metric, ...self::JANUARY]))->assertOk()->inertiaProps('tie_out.sum_label');

        $this->assertSame('Σ total − terbayar', $label('outstanding'));
        $this->assertSame('Σ terbayar', $label('revenue_paid'));
        $this->assertSame('Σ MRC', $label('mrr'));
    }

    /** @return array<string, string> key => label of a status map of the screen */
    private function screenMap(string $file, string $name): array
    {
        $source = (string) file_get_contents(base_path("resources/js/lib/statuses/{$file}.ts"));
        $block = [];
        $this->assertSame(1, preg_match("/export const {$name} = \{(.*?)\n\} satisfies StatusMap;/s", $source, $block), "{$name} in {$file}.ts");
        preg_match_all("/^\s+'?([a-z0-9_.]+)'?: \{ label: '([^']+)'/m", $block[1], $pairs, PREG_SET_ORDER);

        $map = [];
        foreach ($pairs as [, $key, $label]) {
            $map[$key] = $label;
        }
        $this->assertNotEmpty($map, $name);

        return $map;
    }

    private function technician(string $name): User
    {
        $technician = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now(), 'name' => $name]);
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $this->companyId, 'user_id' => $technician->id, 'status' => 'active']);

        return $technician;
    }

    private function product(): Product
    {
        $unit = Unit::factory()->create(['company_id' => $this->companyId]);
        $category = Category::factory()->create(['company_id' => $this->companyId, 'unit_id' => $unit->id]);

        return Product::factory()->create(['company_id' => $this->companyId, 'category_id' => $category->id, 'unit_id' => $unit->id]);
    }

    private function movement(Product $product, Location $location, string $type, string $createdAt, ?string $referenceType = null, ?int $referenceId = null): StockMovement
    {
        return StockMovement::withoutCompany()->forceCreate([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'to_location_id' => $location->id,
            'movement_type' => $type,
            'quantity' => 2,
            'balance_after' => 2,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
