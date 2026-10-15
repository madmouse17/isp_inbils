<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
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
use Tests\TestCase;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * The row-level reports export every row of the filtered view, not the page on screen: Stock Card
 * as a ledger, Audit Log with the properties the screen shows. The row cap is visible, cells that
 * could run as formulas are neutralised, and no row of another company ever leaves.
 */
class ReportRowExportTest extends TestCase
{
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private User $admin;

    private int $companyId;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->actingAs($this->admin);
    }

    // ---------------------------------------------------------------------------------------------
    // Stock Card
    // ---------------------------------------------------------------------------------------------

    public function test_stock_card_file_holds_the_whole_filtered_ledger_in_screen_order(): void
    {
        $product = $this->product($this->companyId);
        foreach (range(1, 27) as $quantity) {
            $this->movement($product, $quantity, now()->startOfDay()->addMinutes($quantity));
        }
        $this->movement($product, 99, now()->subDays(3));
        $elsewhere = Location::factory()->create(['company_id' => $this->companyId]);
        $this->movement($product, 55, now()->startOfDay()->addMinutes(40), $elsewhere);

        $filters = [
            'product_id' => $product->id,
            'location_id' => $this->location->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
            'sort_by' => 'quantity',
            'sort_dir' => 'asc',
        ];

        $screen = $this->get(route('admin.reports.stock-card', [...$filters, 'per_page' => 10]))->assertOk();
        $this->assertSame(27, $screen->inertiaProps('movements.meta.total'));
        $this->assertCount(10, $screen->inertiaProps('movements.data'));

        $records = $this->csvRecords($this->get(route('admin.reports.stock-card.export', $filters)));

        $this->assertCount(27, $records, 'the file is the whole ledger, not the 10 rows of the page');
        $this->assertSame(array_map(fn ($n) => number_format($n, 2, '.', ''), range(1, 27)), array_column($records, 'Jumlah'));
        // The first page of the screen and the first rows of the file are the same ledger rows.
        $this->assertSame(
            array_column($screen->inertiaProps('movements.data'), 'balance_after'),
            array_column(array_slice($records, 0, 10), 'Saldo setelah'),
        );
        $this->assertSame($product->name, $records[0]['Barang']);
        $this->assertSame('receive', $records[0]['Jenis']);
        $this->assertSame($this->location->name, $records[0]['Ke']);
        $this->assertSame('Reporting fixture', $records[0]['Catatan']);
    }

    public function test_stock_card_file_shows_what_a_movement_points_at(): void
    {
        $product = $this->product($this->companyId);
        $this->movement($product, 5, now(), null, 'Reporting fixture', 'App\\Fixture\\Thing', 42);

        $records = $this->csvRecords($this->get(route('admin.reports.stock-card.export', ['product_id' => $product->id])));

        $this->assertSame('Thing #42', $records[0]['Referensi']);
    }

    public function test_stock_card_export_of_another_companys_product_is_not_found(): void
    {
        $foreign = $this->product((int) $this->userWithRole('admin')->company_id);

        $this->get(route('admin.reports.stock-card.export', ['product_id' => $foreign->id]))->assertNotFound();
    }

    public function test_the_row_cap_leaves_a_marker_row_and_a_truncation_header(): void
    {
        config(['exports.max_rows' => 5]);
        $product = $this->product($this->companyId);
        foreach (range(1, 8) as $quantity) {
            $this->movement($product, $quantity, now()->startOfDay()->addMinutes($quantity));
        }

        $response = $this->get(route('admin.reports.stock-card.export', ['product_id' => $product->id, 'sort_by' => 'quantity', 'sort_dir' => 'asc']));
        $rows = $this->csvRows($response);

        $this->assertCount(1 + 5 + 1, $rows, 'header + 5 capped rows + marker');
        $this->assertSame(['# Ekspor dipotong pada 5 baris. Persempit filter untuk mengekspor sisa barisnya.'], end($rows));
        $this->assertSame('1', $response->headers->get('X-Export-Truncated'));
        $this->assertSame('5', $response->headers->get('X-Export-Row-Cap'));

        $exact = $this->product($this->companyId);
        foreach (range(1, 5) as $quantity) {
            $this->movement($exact, $quantity, now());
        }
        $response = $this->get(route('admin.reports.stock-card.export', ['product_id' => $exact->id]));
        $this->assertCount(1 + 5, $this->csvRows($response), 'exactly the cap is not truncated');
        $this->assertSame('0', $response->headers->get('X-Export-Truncated'));
    }

    public function test_stock_card_cells_that_could_run_as_formulas_are_neutralised(): void
    {
        $product = $this->product($this->companyId);
        foreach (['=HYPERLINK("http://evil.test","x")', '+cmd|calc', '@SUM(1+1)', '-2+3'] as $index => $note) {
            $this->movement($product, $index + 1, now()->startOfDay()->addMinutes($index), null, $note);
        }

        $records = $this->csvRecords($this->get(route('admin.reports.stock-card.export', ['product_id' => $product->id, 'sort_by' => 'quantity', 'sort_dir' => 'asc'])));

        $this->assertSame(
            ["'=HYPERLINK(\"http://evil.test\",\"x\")", "'+cmd|calc", "'@SUM(1+1)", "'-2+3"],
            array_column($records, 'Catatan'),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Audit Log
    // ---------------------------------------------------------------------------------------------

    public function test_audit_file_applies_the_filters_server_side_and_holds_every_matching_row(): void
    {
        foreach (range(1, 30) as $n) {
            AuditService::log('stress', "action_{$n}", ['n' => $n]);
        }
        AuditService::log('billing', 'other_module', []);

        $screen = $this->get(route('admin.reports.audit-log', ['log_name' => 'stress', 'per_page' => 25]))->assertOk();
        $this->assertCount(25, $screen->inertiaProps('data.data'));

        $records = $this->csvRecords($this->get(route('admin.reports.audit-log.export', ['log_name' => 'stress'])));

        $this->assertCount(30, $records, 'every matching row, not the 25 of the page');
        $this->assertSame(['stress'], array_values(array_unique(array_column($records, 'Modul'))));
        $this->assertSame($this->admin->name, $records[0]['Pelaku']);
        $this->assertSame((string) $this->admin->id, $records[0]['ID pelaku']);
    }

    public function test_audit_file_properties_are_exactly_what_the_screen_shows(): void
    {
        AuditService::log('billing', 'invoice_generated', ['amount' => 100000, 'note' => 'Q1 ünïcode / slash']);

        $screen = $this->get(route('admin.reports.audit-log', ['log_name' => 'billing']))->assertOk();
        $screenProperties = $screen->inertiaProps('data.data.0.properties');

        $records = $this->csvRecords($this->get(route('admin.reports.audit-log.export', ['log_name' => 'billing'])));

        $this->assertSame($screenProperties, json_decode((string) $records[0]['Properti'], true));
        $this->assertStringContainsString('Q1 ünïcode / slash', (string) $records[0]['Properti']);
    }

    public function test_the_audit_pdf_leaves_the_properties_out(): void
    {
        AuditService::log('billing', 'invoice_generated', ['amount' => 100000]);

        $headers = [];
        View::composer('exports.table', function ($view) use (&$headers): void {
            $headers = $view->getData()['headers'];
        });

        $response = $this->get(route('admin.reports.audit-log.export', ['format' => 'pdf']));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());

        $this->assertContains('Deskripsi', $headers);
        $this->assertNotContains('Properti', $headers);
    }

    public function test_audit_file_never_holds_a_row_of_another_company(): void
    {
        AuditService::log('billing', 'mine_one', ['x' => 1]);
        $other = $this->userWithRole('admin');
        $this->actingAs($other);
        AuditService::log('billing', 'theirs_one', ['x' => 2]);
        $this->actingAs($this->admin);

        $records = $this->csvRecords($this->get(route('admin.reports.audit-log.export')));
        $descriptions = array_column($records, 'Deskripsi');

        $this->assertContains('mine_one', $descriptions);
        $this->assertNotContains('theirs_one', $descriptions);
    }

    public function test_audit_cells_that_could_run_as_formulas_are_neutralised(): void
    {
        AuditService::log('billing', '=cmd|calc', []);
        AuditService::log('billing', '@SUM(A1)', []);

        $descriptions = array_column($this->csvRecords($this->get(route('admin.reports.audit-log.export', ['log_name' => 'billing', 'sort_by' => 'id', 'sort_dir' => 'asc']))), 'Deskripsi');

        $this->assertSame(["'=cmd|calc", "'@SUM(A1)"], $descriptions);
    }

    private function product(int $companyId): Product
    {
        $unit = Unit::factory()->create(['company_id' => $companyId]);
        $category = Category::factory()->create(['company_id' => $companyId, 'unit_id' => $unit->id]);

        return Product::factory()->create(['company_id' => $companyId, 'category_id' => $category->id, 'unit_id' => $unit->id]);
    }

    private function movement(Product $product, int $quantity, $createdAt, ?Location $location = null, string $note = 'Reporting fixture', ?string $referenceType = null, ?int $referenceId = null): StockMovement
    {
        return StockMovement::withoutCompany()->forceCreate([
            'company_id' => $product->company_id,
            'product_id' => $product->id,
            'to_location_id' => ($location ?? $this->location)->id,
            'movement_type' => 'receive',
            'quantity' => $quantity,
            'balance_after' => $quantity,
            'reserved_after' => 0,
            'created_by' => $this->admin->id,
            'note' => $note,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
