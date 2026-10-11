<?php

namespace Tests\Feature\Support;

use App\Models\Core\Company;
use App\Models\User;
use App\Support\ExportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Behaviour the report exports rely on in the shared export helper: a visible row-cap marker,
 * formula-safe CSV cells, a builder-less rows source and relation loading that does not issue
 * one query per exported row.
 */
class ExportQueryHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const MARKER = '# Export truncated at 3 rows. Narrow the filters to export the remaining rows.';

    public function test_csv_over_the_cap_ends_with_a_marker_row_and_signals_truncation_in_headers(): void
    {
        Company::factory()->count(5)->create();

        $response = $this->companyExport(3);
        $rows = $this->parseCsv($response);

        $this->assertCount(1 + 3 + 1, $rows, 'header + 3 rows + marker');
        $this->assertSame([self::MARKER], end($rows));
        $this->assertSame('1', $response->headers->get('X-Export-Truncated'));
        $this->assertSame('3', $response->headers->get('X-Export-Row-Cap'));
    }

    public function test_csv_with_exactly_the_cap_is_not_marked_truncated(): void
    {
        Company::factory()->count(3)->create();

        $response = $this->companyExport(3);
        $rows = $this->parseCsv($response);

        $this->assertCount(1 + 3, $rows);
        $this->assertNotContains([self::MARKER], $rows);
        $this->assertSame('0', $response->headers->get('X-Export-Truncated'));
    }

    public function test_pdf_over_the_cap_carries_the_truncation_header(): void
    {
        Company::factory()->count(5)->create();

        $response = ExportQuery::make(Company::query()->orderBy('id'))
            ->maxRows(3)
            ->streamPdf('Companies', ['Name'], ['Name' => fn (Company $company) => $company->name], 'companies.pdf');

        $this->assertSame('1', $response->headers->get('X-Export-Truncated'));
        $this->assertStringStartsWith('%PDF-', $this->capture($response));
    }

    public function test_csv_cells_that_could_run_as_formulas_are_neutralised(): void
    {
        $cells = ['=SUM(A1:A9)', '+1+1', '-2+3', '@cmd', "\tTAB", "\rCR", '-12.50', '+62812345678', 'plain'];

        $rows = $this->parseCsv(
            ExportQuery::fromRows(array_map(fn (string $cell): array => ['Value' => $cell], $cells))
                ->streamCsv(['Value'], ['Value'], 'cells.csv')
        );
        array_shift($rows);

        $this->assertSame([
            ["'=SUM(A1:A9)"],
            ["'+1+1"],
            ["'-2+3"],
            ["'@cmd"],
            ["'\tTAB"],
            ["'\rCR"],
            ['-12.50'],
            ['+62812345678'],
            ['plain'],
        ], $rows);
    }

    public function test_rows_source_streams_a_bom_header_and_rows_as_csv_and_a_pdf(): void
    {
        $rows = [['Metric' => 'MRR', 'Value' => '100000.00'], ['Metric' => 'Churn', 'Value' => 3]];
        $columns = ['Metric', 'Value'];

        $csv = $this->capture(ExportQuery::fromRows($rows)->streamCsv($columns, $columns, 'summary.csv'));
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertSame(
            [['Metric', 'Value'], ['MRR', '100000.00'], ['Churn', '3']],
            $this->parseCsv(ExportQuery::fromRows($rows)->streamCsv($columns, $columns, 'summary.csv')),
        );

        $pdf = $this->capture(ExportQuery::fromRows($rows)->streamPdf('Summary', $columns, $columns, 'summary.pdf'));
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_eager_loaded_relations_do_not_cost_a_query_per_exported_row(): void
    {
        $company = Company::factory()->create();
        User::factory()->count(6)->create(['company_id' => $company->id]);

        DB::enableQueryLog();
        $rows = $this->parseCsv(
            ExportQuery::make(User::query()->with('company')->orderBy('id'))
                ->maxRows(100)
                ->streamCsv(
                    ['Email', 'Company'],
                    ['Email' => fn (User $user) => $user->email, 'Company' => fn (User $user) => $user->company->name],
                    'users.csv',
                )
        );
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(1 + 6, $rows);
        $this->assertLessThanOrEqual(4, $queries, 'export issued '.$queries.' queries for 6 rows');
    }

    private function companyExport(int $cap): StreamedResponse
    {
        return ExportQuery::make(Company::query()->orderBy('id'))
            ->maxRows($cap)
            ->streamCsv(['Name'], ['Name' => fn (Company $company) => $company->name], 'companies.csv');
    }

    private function capture(StreamedResponse $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    /** @return list<list<string|null>> */
    private function parseCsv(StreamedResponse $response): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $this->capture($response)));
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
