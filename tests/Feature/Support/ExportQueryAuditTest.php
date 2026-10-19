<?php

namespace Tests\Feature\Support;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\AuditProperties;
use App\Support\ExportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Every file a user downloads through ExportQuery leaves one `export` audit row, written after the last row
 * was streamed. PDF rows are capped by cells because DomPDF holds the whole table in memory.
 */
class ExportQueryAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::factory()->create(['is_active' => true]);
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($this->user);
    }

    public function test_a_csv_export_writes_one_export_row_with_report_filters_and_row_count(): void
    {
        Company::factory()->count(2)->create();
        $request = Request::create('/export', 'GET', ['search' => 'abc', 'format' => 'csv']);

        $response = ExportQuery::make(Company::query()->orderBy('id'))
            ->fromRequest($request)
            ->maxRows(100)
            ->streamCsv(['Name'], ['Name' => fn (Company $c) => $c->name], 'companies.csv');

        $this->assertSame(0, Activity::query()->where('log_name', 'export')->count(), 'nothing is logged before the stream runs');

        $this->capture($response);

        $row = Activity::query()->where('log_name', 'export')->sole();
        $this->assertSame('export.csv', $row->description);
        $this->assertSame($this->user->id, $row->causer_id);
        $this->assertNull($row->subject_id);
        $this->assertSame(3, $row->properties['rows']);
        $this->assertFalse($row->properties['truncated']);
        $this->assertNull($row->properties['report']);
        $this->assertSame(['search' => AuditProperties::MASKED], $row->properties['filters'], 'a typed search term is never stored');
    }

    public function test_filled_search_terms_are_masked_in_the_row_and_other_filters_stay(): void
    {
        $request = Request::create('/export', 'GET', ['q' => '081234567890', 'search' => 'budi@example.test', 'status' => 'active', 'format' => 'csv']);

        $this->capture(ExportQuery::fromRows([['A' => '1']])
            ->fromRequest($request)
            ->maxRows(5)
            ->streamCsv(['A'], ['A'], 'a.csv'));

        $row = Activity::query()->where('log_name', 'export')->sole();
        $this->assertSame(['q' => AuditProperties::MASKED, 'search' => AuditProperties::MASKED, 'status' => 'active'], $row->properties['filters']);
        $this->assertStringNotContainsString('081234567890', $row->properties->toJson());
    }

    public function test_a_truncated_export_records_truncated_true(): void
    {
        Company::factory()->count(5)->create();

        $this->capture(ExportQuery::make(Company::query()->orderBy('id'))
            ->maxRows(3)
            ->streamCsv(['Name'], ['Name' => fn (Company $c) => $c->name], 'companies.csv'));

        $row = Activity::query()->where('log_name', 'export')->sole();
        $this->assertSame(3, $row->properties['rows']);
        $this->assertTrue($row->properties['truncated']);
    }

    public function test_a_pdf_export_is_capped_by_cells_and_says_so(): void
    {
        $headers = array_map(fn (int $n): string => 'Kolom '.$n, range(1, 9));
        $rows = array_fill(0, 170, array_fill_keys($headers, 'x'));

        $response = ExportQuery::fromRows($rows)
            ->maxRows(5000)
            ->streamPdf('Log audit', $headers, $headers, 'log.pdf');

        $this->assertSame('166', $response->headers->get('X-Export-Row-Cap'));
        $this->assertSame('1', $response->headers->get('X-Export-Truncated'));
        $this->assertStringStartsWith('%PDF-', $this->capture($response));

        $row = Activity::query()->where('log_name', 'export')->sole();
        $this->assertSame('export.pdf', $row->description);
        $this->assertSame(166, $row->properties['rows']);
        $this->assertTrue($row->properties['truncated']);

        $view = view('exports.table', ['title' => 'Log audit', 'headers' => ['A'], 'rows' => [], 'capped' => true, 'maxRows' => 166])->render();
        $this->assertStringContainsString('PDF dibatasi 166 baris. Untuk data lengkap, unduh CSV atau persempit periode.', $view);
    }

    public function test_a_single_column_pdf_is_capped_at_1500_rows(): void
    {
        $response = ExportQuery::fromRows([['A' => '1']])->maxRows(5000)->streamPdf('T', ['A'], ['A'], 't.pdf');

        $this->assertSame('1500', $response->headers->get('X-Export-Row-Cap'));
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
