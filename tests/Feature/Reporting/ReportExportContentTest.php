<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * The files of the Business, SLA and Asset reports carry exactly the figures their screens show,
 * with their v1 quirks, and never a row of another company. Expected figures are worked out by hand
 * from the fixtures below, not read back from the queries.
 */
class ReportExportContentTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

    private User $admin;

    private User $otherAdmin;

    private int $companyId;

    private int $otherCompanyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $this->admin = $this->userWithRole('admin');
        $this->otherAdmin = $this->userWithRole('admin');
        $this->companyId = (int) $this->admin->company_id;
        $this->otherCompanyId = (int) $this->otherAdmin->company_id;
        // The edge fixtures are pinned to calendar days of the storage timezone (UTC).
        Company::query()->whereKey([$this->companyId, $this->otherCompanyId])->update(['timezone' => 'UTC']);
    }

    public function test_business_file_carries_the_figures_the_screen_shows(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $screen = $this->get(route('admin.reports.business', self::JANUARY))->assertOk();
        $figures = $this->csvFigures($this->get(route('admin.reports.business.export', self::JANUARY)));

        $expected = [
            'MRR' => 700000,                  // active S1 + S2 + S6; the suspended, terminated and deleted ones are out
            'Langganan aktif' => 3,
            'Langganan ditangguhkan' => 1,
            'Langganan berhenti' => 2,
            'Langganan baru' => 3,            // S1, S2, S3 created in January
            'Churn' => 1,                     // S4 terminated on the last second of January
            'Pendapatan terbayar' => 496000,  // I1 300000 + I2 150000 + I3 paid_amount 46000
            'Pendapatan berulang' => 450000,
            'Pendapatan sekali bayar' => 46000,
            'Piutang' => 465000,              // I5 300000 + I6 120000 + I7 75000 + overpaid I10 -30000
            'Kepatuhan SLA' => 60.0,          // all time, ignores January: 3 of 5 resolved tickets on time
            'Pemasangan selesai' => 2,
        ];

        foreach ($expected as $metric => $value) {
            $this->assertArrayHasKey($metric, $figures, "{$metric} is missing from the file");
            $this->assertEquals($value, (float) $figures[$metric], "{$metric} in the file");
        }

        $this->assertEquals(1, (float) $figures['Sebaran aset / available']);
        $this->assertEquals(2, (float) $figures['Sebaran aset / installed']);
        $this->assertEquals(1, (float) $figures['Sebaran aset / retired']);

        // What the file says is what the page says.
        $this->assertEquals($screen->inertiaProps('data.mrr'), (float) $figures['MRR']);
        $this->assertEquals($screen->inertiaProps('data.revenue_paid'), (float) $figures['Pendapatan terbayar']);
        $this->assertEquals($screen->inertiaProps('data.outstanding'), (float) $figures['Piutang']);
        $this->assertEquals($screen->inertiaProps('data.sla_compliance_pct'), (float) $figures['Kepatuhan SLA']);
    }

    public function test_business_file_labels_scope_filters_and_version_of_every_figure(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $records = collect($this->csvRecords($this->get(route('admin.reports.business.export', self::JANUARY))))
            ->keyBy(fn (array $record): string => $record['Metrik'].'|'.$record['Rincian']);

        $this->assertSame('Posisi saat ini: filter periode tidak berlaku', $records['MRR|']['Cakupan']);
        $this->assertSame('Periode: 2026-01-01 sampai 2026-01-31', $records['Pendapatan terbayar|']['Cakupan']);
        $this->assertSame('date_from=2026-01-01; date_to=2026-01-31', $records['MRR|']['Filter']);
        $this->assertSame('v1', $records['MRR|']['Versi']);
        $this->assertSame('currency', $records['MRR|']['Satuan']);

        $unfiltered = collect($this->csvRecords($this->get(route('admin.reports.business.export'))))
            ->keyBy(fn (array $record): string => $record['Metrik'].'|'.$record['Rincian']);
        $this->assertSame('Periode: tanpa rentang', $unfiltered['Pendapatan terbayar|']['Cakupan']);
        $this->assertSame('tidak ada', $unfiltered['Pendapatan terbayar|']['Filter']);
        // Without a range the period figures cover every record.
        foreach (['Periode: dari 2026-01-01' => ['date_from' => '2026-01-01'], 'Periode: sampai 2026-01-31' => ['date_to' => '2026-01-31']] as $scope => $half) {
            $records = collect($this->csvRecords($this->get(route('admin.reports.business.export', $half))))
                ->keyBy(fn (array $record): string => $record['Metrik'].'|'.$record['Rincian']);
            $this->assertSame($scope, $records['Pendapatan terbayar|']['Cakupan']);
        }
        $this->assertEquals(7496000, (float) $unfiltered['Pendapatan terbayar|']['Nilai']);   // I1 + I2 + I3 + the December I4 of 7000000
    }

    public function test_a_company_never_receives_the_rows_of_another_company(): void
    {
        $this->seedBusinessWorld();

        $mine = $this->csvFigures($this->actingAs($this->admin)->get(route('admin.reports.business.export', self::JANUARY)));
        $this->assertEquals(496000, (float) $mine['Pendapatan terbayar']);

        $theirs = $this->csvFigures($this->actingAs($this->otherAdmin)->get(route('admin.reports.business.export', self::JANUARY)));
        $this->assertEquals(9000000, (float) $theirs['Pendapatan terbayar'], 'company B sees only its own invoice');
        $this->assertEquals(5000000, (float) $theirs['MRR']);
        $this->assertEquals(1, (float) $theirs['Langganan aktif']);
    }

    public function test_sla_file_matches_the_screen_including_the_unfiltered_category_breakdown(): void
    {
        $this->seedSlaWorld();
        $this->actingAs($this->admin);

        $screen = $this->get(route('admin.reports.sla', self::JANUARY))->assertOk();
        $figures = $this->csvFigures($this->get(route('admin.reports.sla.export', self::JANUARY)));

        $this->assertEquals(4, (float) $figures['Tiket selesai']);         // T1..T4 resolved in January
        $this->assertEquals(2, (float) $figures['Sesuai SLA']);            // T1, T2 (resolved exactly at the deadline)
        $this->assertEquals(50.0, (float) $figures['Tingkat kepatuhan SLA']);
        $this->assertEquals(1, (float) $figures['Pelanggaran SLA']);       // T3 one second late
        $this->assertEquals(113, (float) $figures['Rata-rata waktu penyelesaian']);   // (60 + 120 + 240 + 30) / 4 = 112.5 rounds to 113
        $this->assertEquals(3, (float) $figures['Tiket selesai per kategori / Support: total']);   // v1: ignores the period, so T6 counts
        $this->assertEquals(3, (float) $figures['Tiket selesai per kategori / Support: sesuai SLA']);
        $this->assertEquals(2, (float) $figures['Tiket selesai per kategori / Outage: total']);
        $this->assertEquals(0, (float) $figures['Tiket selesai per kategori / Outage: sesuai SLA']);

        $this->assertSame($screen->inertiaProps('data.total_resolved'), (int) $figures['Tiket selesai']);
        $this->assertEquals($screen->inertiaProps('data.sla_rate'), (float) $figures['Tingkat kepatuhan SLA']);
    }

    public function test_asset_file_reproduces_which_figures_honour_the_filters(): void
    {
        $this->seedAssetWorld();
        $this->actingAs($this->admin);

        $all = $this->csvFigures($this->get(route('admin.reports.asset.export', self::JANUARY)));
        $this->assertEquals(1, (float) $all['Sebaran status / available']);
        $this->assertEquals(2, (float) $all['Sebaran status / installed']);
        $this->assertEquals(1, (float) $all['Sebaran status / retired']);
        $this->assertEquals(3, (float) $all['Aset per lokasi / POP Central']);
        $this->assertEquals(1, (float) $all['Aset per lokasi / POP East']);
        $this->assertEquals(1, (float) $all['Pemasangan aset']);            // N1 inside January, N2 a day early
        $this->assertEquals(2, (float) $all['Terhubung ke pelanggan']);
        $this->assertEquals(1, (float) $all['Dipensiunkan']);

        // v1: the type filter narrows the status distribution only.
        $routers = $this->csvFigures($this->get(route('admin.reports.asset.export', [...self::JANUARY, 'asset_type' => 'router'])));
        $this->assertEquals(1, (float) $routers['Sebaran status / installed']);
        $this->assertEquals(2, (float) $routers['Terhubung ke pelanggan']);
        $this->assertEquals(3, (float) $routers['Aset per lokasi / POP Central']);
    }

    public function test_pdf_is_a_pdf_with_the_same_title_and_a_download_name(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $response = $this->get(route('admin.reports.business.export', ['format' => 'pdf', ...self::JANUARY]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename=business-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', $response->streamedContent());
    }

    public function test_the_pdf_table_view_is_indonesian_with_a_row_count_and_an_empty_state(): void
    {
        $empty = view('exports.table', ['title' => 'Metrik Bisnis', 'headers' => ['Metrik', 'Nilai'], 'rows' => [], 'capped' => false, 'maxRows' => 5000])->render();

        $this->assertStringContainsString('<html lang="id">', $empty);
        $this->assertStringContainsString('Baris: 0', $empty);
        $this->assertStringContainsString('Tidak ada data.', $empty);
        $this->assertStringNotContainsString('dibatasi sampai', $empty);

        $capped = view('exports.table', ['title' => 'Metrik Bisnis', 'headers' => ['Metrik'], 'rows' => [['Metrik' => 'MRR']], 'capped' => true, 'maxRows' => 1])->render();

        $this->assertStringContainsString('Baris: 1 (dibatasi sampai 1)', $capped);
        $this->assertStringNotContainsString('Tidak ada data.', $capped);
    }

    public function test_the_business_file_headings_are_indonesian(): void
    {
        $this->seedBusinessWorld();
        $this->actingAs($this->admin);

        $rows = $this->csvRows($this->get(route('admin.reports.business.export', self::JANUARY)));

        $this->assertSame(['Metrik', 'Rincian', 'Nilai', 'Satuan', 'Cakupan', 'Filter', 'Versi'], $rows[0]);
    }

    public function test_drill_export_uses_company_timezone(): void
    {
        $support = $this->worldCategory($this->companyId, 'Support', 'SUP-TZ');
        $this->worldTicket($this->companyId, $support, '2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 12:00:00', '2026-01-12 16:00:00', responseDeadline: '2026-01-12 09:00:00');
        Company::query()->whereKey($this->companyId)->update(['timezone' => 'Asia/Jakarta']);
        $this->actingAs($this->admin);

        $records = $this->csvRecords($this->get(route('admin.reports.sla.drill.export', ['metric' => 'total_resolved', ...self::JANUARY])));

        // Stored in UTC, written the way the company's staff read the clock (UTC+7), without the offset a spreadsheet cannot use.
        $this->assertCount(1, $records);
        $this->assertSame('2026-01-12 15:00', $records[0]['Dibuat']);
        $this->assertSame('2026-01-12 15:30', $records[0]['Respons pertama']);
        $this->assertSame('2026-01-12 16:00', $records[0]['Target respons']);
        $this->assertSame('2026-01-12 19:00', $records[0]['Selesai']);
        $this->assertSame('2026-01-12 23:00', $records[0]['Batas SLA']);
    }

    private function seedBusinessWorld(): void
    {
        $a = $this->companyId;
        $b = $this->otherCompanyId;

        $this->worldSubscription($a, 'active', 100000, '2026-01-01 00:00:00');
        $this->worldSubscription($a, 'active', 200000, '2026-01-31 23:59:59');
        $this->worldSubscription($a, 'suspended', 50000, '2026-01-15 10:00:00');
        $this->worldSubscription($a, 'terminated', 70000, '2025-06-01 00:00:00', '2026-01-31 23:59:59');
        $this->worldSubscription($a, 'terminated', 80000, '2025-06-01 00:00:00', '2025-12-31 23:59:59');
        $this->worldSubscription($a, 'active', 400000, '2025-12-31 23:59:59');
        $this->worldSubscription($a, 'active', 900000, '2026-01-20 00:00:00')->delete();
        $this->worldSubscription($b, 'active', 5000000, '2026-01-10 08:00:00');

        $this->worldInvoice($a, 'recurring', 'paid', 300000, 300000, '2026-01-01');
        $this->worldInvoice($a, 'recurring', 'paid', 150000, 150000, '2026-01-31');
        $this->worldInvoice($a, 'one_time', 'paid', 40000, 46000, '2026-01-15');
        $this->worldInvoice($a, 'recurring', 'paid', 7000000, 7000000, '2025-12-31');
        $this->worldInvoice($a, 'recurring', 'partial', 500000, 200000, '2026-01-10');
        $this->worldInvoice($a, 'recurring', 'sent', 120000, 0, '2026-01-12');
        $this->worldInvoice($a, 'one_time', 'overdue', 80000, 5000, '2025-11-05');
        $this->worldInvoice($a, 'one_time', 'draft', 999000, 0, '2026-01-20');
        $this->worldInvoice($a, 'recurring', 'sent', 100000, 130000, '2026-01-13');
        $this->worldInvoice($b, 'recurring', 'paid', 9000000, 9000000, '2026-01-10');

        $this->worldWorkOrder($a, 'installation', 'completed', '2026-01-01 00:00:00');
        $this->worldWorkOrder($a, 'installation', 'completed', '2026-01-31 23:59:59');
        $this->worldWorkOrder($a, 'installation', 'completed', '2025-12-31 23:59:59');
        $this->worldWorkOrder($a, 'maintenance', 'completed', '2026-01-15 09:00:00');
        $this->worldWorkOrder($a, 'installation', 'in_progress', null);
        $this->worldWorkOrder($b, 'installation', 'completed', '2026-01-15 10:00:00');

        $this->seedSlaWorld();
        $this->seedAssetWorld();
    }

    private function seedSlaWorld(): void
    {
        $a = $this->companyId;
        $support = $this->worldCategory($a, 'Support', 'SUP-1');
        $outage = $this->worldCategory($a, 'Outage', 'OUT');

        $this->worldTicket($a, $support, '2026-01-10 08:00:00', '2026-01-10 08:10:00', '2026-01-10 09:00:00', '2026-01-10 10:00:00');
        $this->worldTicket($a, $support, '2026-01-11 08:00:00', null, '2026-01-11 10:00:00', '2026-01-11 10:00:00', 'closed');
        $this->worldTicket($a, $outage, '2026-01-12 08:00:00', '2026-01-12 08:30:00', '2026-01-12 12:00:00', '2026-01-12 11:59:59');
        $this->worldTicket($a, $outage, '2026-01-13 08:00:00', '2026-01-13 08:20:00', '2026-01-13 08:30:00', null);
        $this->worldTicket($a, $support, '2026-01-15 08:00:00', null, null, '2026-01-15 12:00:00');
        $this->worldTicket($a, $support, '2025-12-31 20:00:00', null, '2025-12-31 23:59:59', '2026-01-01 00:00:00');

        $supportB = $this->worldCategory($this->otherCompanyId, 'Support', 'SUP-B');
        $this->worldTicket($this->otherCompanyId, $supportB, '2026-01-05 08:00:00', null, '2026-01-05 09:00:00', '2026-01-05 12:00:00');
    }

    private function seedAssetWorld(): void
    {
        $a = $this->companyId;
        $central = $this->worldLocation($a, 'POP-C', 'POP Central');
        $east = $this->worldLocation($a, 'POP-E', 'POP East');

        $this->worldAsset($a, 'router', 'available', $central);
        $router = $this->worldAsset($a, 'router', 'installed', $central, true);
        $this->worldAsset($a, 'router', 'retired', $central);
        $switch = $this->worldAsset($a, 'switch', 'installed', $east, true);

        $this->worldInstallation($a, $switch, $east, '2026-01-01 00:00:00');
        $this->worldInstallation($a, $router, $central, '2025-12-31 23:59:59');

        $centralB = $this->worldLocation($this->otherCompanyId, 'POP-C', 'POP Central');
        $this->worldAsset($this->otherCompanyId, 'switch', 'available', $centralB);
    }
}
