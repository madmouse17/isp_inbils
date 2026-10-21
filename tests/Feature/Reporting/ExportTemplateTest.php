<?php

namespace Tests\Feature\Reporting;

use App\Models\Core\Company;
use App\Models\Core\EmployeeProfile;
use App\Models\User;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyLogoService;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;
use Tests\Traits\BuildsReportingWorld;
use Tests\Traits\CreatesReportingUsers;
use Tests\Traits\ParsesCsvDownloads;

/**
 * K32: the PDF of a report is a document an owner can send: a header with the company, the title, the period, the
 * filters in words and who printed it and when; figures written the way the screen writes them (Rp, dates, hours and
 * minutes); page numbers; and a file far smaller than the one that embedded the whole font. The CSV keeps one header
 * row and raw figures.
 */
class ExportTemplateTest extends TestCase
{
    use BuildsReportingWorld;
    use CreatesReportingUsers;
    use ParsesCsvDownloads;
    use RefreshDatabase;

    private const JANUARY = ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'];

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
        Company::query()->whereKey($this->companyId)->update(['name' => 'PT Jaringan Nusantara', 'timezone' => 'UTC']);
        $this->actingAs($this->admin);

        View::composer('exports.table', function ($view): void {
            $this->view = $view->getData();
        });
    }

    public function test_the_header_names_the_company_the_period_the_filters_and_who_printed_it(): void
    {
        $technician = $this->technician('Teknisi Satu');

        $pdf = $this->get(route('admin.reports.technician.export', ['format' => 'pdf', 'technician_id' => $technician->public_id, ...self::JANUARY]))->streamedContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame('PT Jaringan Nusantara', $this->view['company']['name']);
        $this->assertSame('Kinerja Teknisi', $this->view['title']);
        $this->assertSame('01 Jan 2026 sampai 31 Jan 2026', $this->view['period'], 'the company date format, `d M Y`, as the screen writes it');
        $this->assertSame(['Teknisi: Teknisi Satu'], $this->view['filters'], 'the technician by name, never by id');
        $this->assertSame($this->admin->name, $this->view['printedBy']);
        $this->assertMatchesRegularExpression('/^\d{1,2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}$/', $this->view['printedAt']);

        $html = view('exports.table', $this->view)->render();

        $this->assertStringContainsString('PT Jaringan Nusantara', $html);
        $this->assertStringContainsString('Kinerja Teknisi', $html);
        $this->assertStringContainsString('Periode: 01 Jan 2026 sampai 31 Jan 2026', $html);
        $this->assertStringContainsString('Filter: Teknisi: Teknisi Satu', $html);
        $this->assertStringContainsString('Dicetak '.$this->view['printedAt'].' oleh '.e($this->admin->name), $html);
        $this->assertDoesNotMatchRegularExpression('/\b[0-9A-HJKMNP-TV-Z]{26}\b/', $html, 'no id on a page a person reads');
    }

    public function test_a_company_without_a_logo_has_none_in_its_header(): void
    {
        $this->get(route('admin.reports.business.export', ['format' => 'pdf']))->streamedContent();

        $this->assertNull($this->view['company']['logo']);
        $this->assertStringNotContainsString('<img', view('exports.table', $this->view)->render());
    }

    public function test_the_logo_of_the_company_is_embedded_in_the_header(): void
    {
        Storage::fake('public');
        CompanyLogoService::store(Company::query()->findOrFail($this->companyId), UploadedFile::fake()->image('logo.png', 80, 40));

        $pdf = $this->get(route('admin.reports.business.export', ['format' => 'pdf']))->streamedContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringStartsWith('data:image/webp;base64,', $this->view['company']['logo']);
        $this->assertStringContainsString('<img class="logo" src="data:image/webp;base64,', view('exports.table', $this->view)->render());
    }

    public function test_figures_are_written_the_way_the_screen_writes_them(): void
    {
        $c = $this->companyId;
        $this->worldSubscription($c, 'active', 100000, '2026-01-01 00:00:00');
        $this->worldSubscription($c, 'active', 200000, '2026-01-02 00:00:00');
        $this->worldSubscription($c, 'active', 400000, '2025-12-31 23:59:59');
        $this->worldInvoice($c, 'recurring', 'paid', 1250000, 1250000, '2026-01-10');
        // Resolved 113 minutes after it was made, answered after 66, within its deadline.
        $this->worldTicket($c, $this->worldCategory($c, 'Support', 'SUP'), '2026-01-12 08:00:00', '2026-01-12 09:06:00', '2026-01-12 09:53:00', '2026-01-12 12:00:00');

        $business = $this->pdfFigures('admin.reports.business.export');

        $this->assertSame('Rp 700.000', $business['MRR|']);
        $this->assertSame('Rp 1.250.000', $business['Pendapatan terbayar|']);
        $this->assertSame('3', $business['Langganan aktif|']);
        $this->assertSame('100%', $business['Kepatuhan SLA|']);
        $this->assertSame(['Metrik', 'Rincian', 'Nilai', 'Satuan', 'Cakupan', 'Versi'], $this->view['headers'], 'the filters are in the header, so the table has no column of them');
        $this->assertSame(['Nilai'], $this->view['figures']);

        $sla = $this->pdfFigures('admin.reports.sla.export');

        $this->assertSame('1', $sla['Tiket selesai|']);
        $this->assertSame('1 j 53 m', $sla['Rata-rata waktu penyelesaian|']);
        $this->assertSame('1 j 6 m', $sla['Rata-rata respons pertama|']);
        $this->assertSame('100%', $sla['Tingkat kepatuhan SLA|']);
    }

    public function test_the_csv_keeps_one_header_row_and_the_plain_figure(): void
    {
        $this->worldSubscription($this->companyId, 'active', 700000, '2026-01-01 00:00:00');

        $rows = $this->csvRows($this->get(route('admin.reports.business.export', self::JANUARY)));

        $this->assertSame(['Metrik', 'Rincian', 'Nilai', 'Satuan', 'Cakupan', 'Filter', 'Versi'], $rows[0]);
        $records = collect($this->csvRecords($this->get(route('admin.reports.business.export', self::JANUARY))))->keyBy('Metrik');
        $this->assertEquals(700000, (float) $records['MRR']['Nilai']);
        $this->assertStringNotContainsString('Rp', (string) $records['MRR']['Nilai']);
        $this->assertSame('Rupiah', $records['MRR']['Satuan']);
        $this->assertCount(1, array_filter($rows, fn (array $row): bool => $row[0] === 'Metrik'), 'one header row, no title or period lines above the table');
    }

    public function test_a_pdf_is_numbered_and_far_smaller_than_the_full_font(): void
    {
        foreach (range(1, 120) as $n) {
            AuditService::log('stress', "action_{$n}", ['n' => $n]);
        }

        $pdf = $this->get(route('admin.reports.audit-log.export', ['format' => 'pdf', 'log_name' => 'stress']))->streamedContent();

        $pages = preg_match_all('/\/Type\s*\/Page\b/', $pdf);
        $this->assertGreaterThanOrEqual(3, $pages);
        $this->assertLessThan(200_000, strlen($pdf), 'a subset of the font, not the 880 KB of the whole one');
        $text = $this->pdfText($pdf);
        foreach (range(1, $pages) as $page) {
            // The font is a subset with a Unicode encoding, so the text is in the stream as UTF-16 or as plain bytes.
            $needle = "Halaman {$page} dari {$pages}";
            $this->assertTrue(str_contains($text, $needle) || str_contains($text, mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8')), $needle);
        }
    }

    public function test_a_column_type_applies_to_the_pdf_only_and_a_misfit_is_printed_as_it_is(): void
    {
        CompanyService::useCompanyForTests($this->companyId);
        $rows = [
            ['Nama' => 'A', 'Total' => '1250000.00', 'Tanggal' => '2026-10-18', 'Waktu' => '2026-10-18 14:30', 'Lama' => 135, 'Rasio' => 63.6, 'Jumlah' => 1234, 'Bebas' => 'tetap', 'Campur' => '2500'],
            ['Nama' => 'B', 'Total' => 'tidak ada', 'Tanggal' => '', 'Waktu' => 'kemarin', 'Lama' => 0, 'Rasio' => 50, 'Jumlah' => 0, 'Bebas' => '', 'Campur' => '12.50'],
        ];
        $columns = array_combine(array_keys($rows[0]), array_keys($rows[0]));
        $export = fn (): ExportQuery => ExportQuery::fromRows($rows)
            ->types([
                'Total' => 'money', 'Tanggal' => 'date', 'Waktu' => 'datetime', 'Lama' => 'duration', 'Rasio' => 'percent', 'Jumlah' => 'number',
                'Campur' => static fn (array $row): ?string => $row['Nama'] === 'A' ? 'money' : null,
            ])
            ->describe('1 Okt 2026 sampai 31 Okt 2026', ['Status: Belum dibayar']);
        $map = static fn (array $row): array => $row;

        $csv = $this->capture($export()->streamCsv($columns, $map, 'types.csv'));
        $this->assertStringContainsString('A,1250000.00,2026-10-18,"2026-10-18 14:30",135,63.6,1234,tetap,2500', $csv, 'the file keeps what it was given');

        $this->capture($export()->streamPdf('Uji tipe', $columns, $map, 'types.pdf'));

        $this->assertSame(
            ['Nama' => 'A', 'Total' => 'Rp 1.250.000', 'Tanggal' => '18 Okt 2026', 'Waktu' => '18 Okt 2026 14:30', 'Lama' => '2 j 15 m', 'Rasio' => '63,6%', 'Jumlah' => '1.234', 'Bebas' => 'tetap', 'Campur' => 'Rp 2.500'],
            $this->view['rows'][0],
        );
        $this->assertSame(
            ['Nama' => 'B', 'Total' => 'tidak ada', 'Tanggal' => '', 'Waktu' => 'kemarin', 'Lama' => '0 m', 'Rasio' => '50%', 'Jumlah' => '0', 'Bebas' => '', 'Campur' => '12.50'],
            $this->view['rows'][1],
            'a value the type does not fit stays as it is, and a column whose function answers null is not typed',
        );
        $this->assertEqualsCanonicalizing(['Total', 'Lama', 'Rasio', 'Jumlah', 'Campur'], $this->view['figures'], 'figures align right, dates do not');
        $this->assertSame('1 Okt 2026 sampai 31 Okt 2026', $this->view['period']);
        $this->assertSame(['Status: Belum dibayar'], $this->view['filters']);

        $html = view('exports.table', $this->view)->render();
        $this->assertStringContainsString('<th class="figure">Total</th>', $html);
        $this->assertMatchesRegularExpression('/<th(?: class="")?\s*>Tanggal<\/th>/', $html);
    }

    /** @return array<string, string> `Metrik|Rincian` => the figure the PDF prints */
    private function pdfFigures(string $route): array
    {
        $this->get(route($route, ['format' => 'pdf', ...self::JANUARY]))->streamedContent();

        $figures = [];
        foreach ($this->view['rows'] as $row) {
            $figures[$row['Metrik'].'|'.$row['Rincian']] = $row['Nilai'];
        }

        return $figures;
    }

    private function technician(string $name): User
    {
        $technician = User::factory()->create(['company_id' => $this->companyId, 'is_active' => true, 'email_verified_at' => now(), 'name' => $name]);
        $this->grantRole($technician, 'technician');
        EmployeeProfile::factory()->create(['company_id' => $this->companyId, 'user_id' => $technician->id, 'status' => 'active']);

        return $technician;
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** The text a PDF draws: its content streams, inflated where they are compressed, which hold the strings as they are. */
    private function pdfText(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

        return implode("\n", array_map(fn (string $stream): string => @gzuncompress($stream) ?: $stream, $streams[1]));
    }
}
