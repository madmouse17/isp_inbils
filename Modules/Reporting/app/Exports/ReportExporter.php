<?php

namespace Modules\Reporting\Exports;

use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Model;
use Modules\Inventory\Models\StockMovement;
use Modules\Reporting\Drill\DrillList;
use Modules\Reporting\Exceptions\ConsolidationRefusedException;
use Modules\Reporting\Http\Requests\ConsolidatedReportRequest;
use Modules\Reporting\Http\Requests\ReportDrillExportRequest;
use Modules\Reporting\Http\Requests\ReportExportRequest;
use Modules\Reporting\Queries\AuditLogQuery;
use Modules\Reporting\Queries\ConsolidatedBusinessQuery;
use Modules\Reporting\Queries\ReportFilters;
use Modules\Reporting\Queries\StockCardQuery;
use Modules\Reporting\Registry\DateBasis;
use Modules\Reporting\Registry\MetricDefinition;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV and PDF files of the reports, through the shared ExportQuery (BOM, formula-safe cells, row
 * cap with a visible marker). Aggregate reports export the same figures the screen shows, one row
 * per metric or breakdown entry; row-level reports export every row of the filtered view.
 */
final class ReportExporter
{
    /** Business figures the package category filter leaves whole: assets and tickets have no package. */
    private const NOT_BY_PACKAGE_CATEGORY = ['asset_distribution', 'sla_compliance_pct'];

    public static function report(ReportExportRequest $request): StreamedResponse
    {
        return match ($request->report()) {
            ReportType::StockCard => self::stockCard($request),
            ReportType::AuditLog => self::auditLog($request),
            default => self::summary($request),
        };
    }

    public static function drill(ReportDrillExportRequest $request): StreamedResponse
    {
        $source = $request->source();
        $kind = $source->kind;
        $list = new DrillList($source, $request->filters(), $request->user());

        $columns = [];
        foreach ($kind->columns() as $column) {
            $columns[$column['label']] = $column['key'];
        }

        $map = static function (Model $model) use ($kind, $columns): array {
            $row = $kind->row($model);
            $mapped = [];
            foreach ($columns as $label => $key) {
                $mapped[$label] = $row[$key] ?? null;
            }

            return $mapped;
        };

        $title = $source->label.($request->input('group') ? ' - '.$request->input('group') : '');
        $stem = $request->report()->slug().'-'.str_replace('_', '-', $source->key);
        $export = ExportQuery::make($list->listing($request->sort(), $request->direction()))->maxRows(self::cap());

        return self::download($export, $request->exportFormat(), $title, $columns, $map, $stem);
    }

    /**
     * The consolidated Business report as CSV: the summary rows of every company, then of the
     * consolidated total, from the same payload the screen shows. Refused (422) like the screen
     * when the companies do not share one currency.
     */
    public static function consolidated(ConsolidatedReportRequest $request): StreamedResponse
    {
        $companies = $request->companies();

        try {
            $report = ConsolidatedBusinessQuery::execute($companies, $request->dateFrom(), $request->dateTo());
        } catch (ConsolidationRefusedException $e) {
            abort(422, $e->getMessage());
        }

        $filters = new ReportFilters(companyId: (int) $request->head()->id, dateFrom: $request->dateFrom(), dateTo: $request->dateTo());
        $names = $companies->pluck('name', 'id');

        $rows = [];
        foreach ($report['rows'] as $row) {
            foreach (self::summaryRows(ReportType::Business, $row['metrics'], $filters) as $line) {
                $rows[] = ['Perusahaan' => $names[$row['company_id']], ...$line];
            }
        }
        foreach (self::summaryRows(ReportType::Business, $report['total'], $filters) as $line) {
            $rows[] = ['Perusahaan' => 'Total konsolidasi', ...$line];
        }

        $headers = ['Perusahaan', 'Metrik', 'Rincian', 'Nilai', 'Satuan', 'Cakupan', 'Filter', 'Versi'];
        $map = static fn (array $row): array => $row;

        return self::download(ExportQuery::fromRows($rows), 'csv', ReportType::Business->title().' (Konsolidasi)', array_combine($headers, $headers), $map, 'business-consolidated');
    }

    private static function summary(ReportExportRequest $request): StreamedResponse
    {
        $report = $request->report();
        $filters = $request->filters();
        $rows = self::summaryRows($report, $filters->payload($report), $filters);

        $headers = ['Metrik', 'Rincian', 'Nilai', 'Satuan', 'Cakupan', 'Filter', 'Versi'];
        $columns = array_combine($headers, $headers);
        $map = static fn (array $row): array => $row;

        return self::download(ExportQuery::fromRows($rows), $request->exportFormat(), $report->title(), $columns, $map, $report->slug());
    }

    private static function stockCard(ReportExportRequest $request): StreamedResponse
    {
        $ledger = StockCardQuery::ledger(
            (int) CompanyService::currentId(),
            $request->integer('product_id'),
            $request->integer('location_id') ?: null,
            $request->dateFrom(),
            $request->dateTo(),
            (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'created_at'),
            (string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'desc'),
        )->with(['product:id,name', 'fromLocation:id,name', 'toLocation:id,name']);

        $columns = [
            'Tanggal' => 'date', 'Barang' => 'product', 'Jenis' => 'type', 'Jumlah' => 'quantity',
            'Saldo setelah' => 'balance_after', 'Dari' => 'from', 'Ke' => 'to', 'Referensi' => 'reference', 'Catatan' => 'note',
        ];
        $map = static fn (StockMovement $movement): array => [
            'Tanggal' => $movement->created_at?->toDateTimeString(),
            'Barang' => $movement->product?->name,
            'Jenis' => $movement->movement_type,
            'Jumlah' => $movement->quantity,
            'Saldo setelah' => $movement->balance_after,
            'Dari' => $movement->fromLocation?->name,
            'Ke' => $movement->toLocation?->name,
            'Referensi' => $movement->reference_type === null ? null : class_basename($movement->reference_type).' #'.$movement->reference_id,
            'Catatan' => $movement->note,
        ];

        return self::download(
            ExportQuery::make($ledger)->maxRows(self::cap()),
            $request->exportFormat(),
            ReportType::StockCard->title(),
            $columns,
            $map,
            ReportType::StockCard->slug(),
        );
    }

    private static function auditLog(ReportExportRequest $request): StreamedResponse
    {
        $listing = AuditLogQuery::listing(
            userId: $request->integer('user_id') ?: null,
            logName: $request->input('log_name'),
            dateFrom: $request->dateFrom(),
            dateTo: $request->dateTo(),
            search: $request->input('search'),
            event: $request->input('event'),
            sortField: (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'created_at'),
            sortDirection: (string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'desc'),
        );

        $columns = [
            'ID' => 'id', 'Waktu' => 'time', 'Modul' => 'module', 'Aksi' => 'event', 'Deskripsi' => 'description',
            'Pelaku' => 'actor', 'ID pelaku' => 'actor_id', 'Jenis subjek' => 'subject_type', 'ID subjek' => 'subject_id',
        ];
        $withProperties = $request->exportFormat() === 'csv';
        if ($withProperties) {
            // Exactly the payload the screen shows to report.audit.view holders; the PDF leaves it out.
            $columns['Properti'] = 'properties';
        }

        $map = static function (Activity $activity) use ($withProperties): array {
            $causer = $activity->causer;
            $row = [
                'ID' => $activity->id,
                'Waktu' => $activity->created_at?->toDateTimeString(),
                'Modul' => $activity->log_name,
                'Aksi' => $activity->event,
                'Deskripsi' => $activity->description,
                'Pelaku' => $causer instanceof User ? $causer->name : ($activity->causer_id ? 'Pengguna #'.$activity->causer_id : 'Sistem'),
                'ID pelaku' => $activity->causer_id,
                'Jenis subjek' => $activity->subject_type,
                'ID subjek' => $activity->subject_id,
            ];

            if ($withProperties) {
                $properties = $activity->properties;
                $row['Properti'] = $properties?->isNotEmpty() ? json_encode($properties->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            }

            return $row;
        };

        return self::download(
            ExportQuery::make($listing)->maxRows(self::cap()),
            $request->exportFormat(),
            ReportType::AuditLog->title(),
            $columns,
            $map,
            ReportType::AuditLog->slug(),
        );
    }

    /**
     * @param  array<string, string>  $columns  header => key of the mapper result
     * @param  callable  $map  whole-row mapper returning values keyed by header
     */
    private static function download(ExportQuery $export, string $format, string $title, array $columns, callable $map, string $stem): StreamedResponse
    {
        $file = $stem.'-'.now()->format('Ymd-His');

        return $format === 'pdf'
            ? $export->streamPdf($title, $columns, $map, $file.'.pdf')
            : $export->streamCsv($columns, $map, $file.'.csv');
    }

    private static function cap(): int
    {
        return (int) config('exports.max_rows', 5000);
    }

    /**
     * One row per metric, or per breakdown entry, in registry order. Scope and filters say which
     * records the figure covers: snapshot figures ignore the period, v1 quirks included.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, string|int|float|null>>
     */
    private static function summaryRows(ReportType $report, array $payload, ReportFilters $filters): array
    {
        $applied = $filters->query($report);
        $filterText = $applied === []
            ? 'tidak ada'
            : implode('; ', array_map(fn (string $key, string|int $value): string => $key.'='.$value, array_keys($applied), $applied));

        $rows = [];
        foreach (MetricRegistry::forReport($report) as $metric) {
            foreach (self::entries($payload[$metric->key] ?? null) as $label => $value) {
                $rows[] = [
                    'Metrik' => $metric->label,
                    'Rincian' => (string) $label,
                    'Nilai' => $value,
                    'Satuan' => $metric->unit->value,
                    'Cakupan' => self::scope($metric, $filters),
                    'Filter' => $filterText,
                    'Versi' => $metric->version,
                ];
            }
        }

        return $rows;
    }

    /**
     * A figure as breakdown entries: a plain figure is one unnamed entry, a map one entry per key,
     * the per-category list three entries per category.
     *
     * @return array<string|int, string|int|float|null>
     */
    private static function entries(mixed $value): array
    {
        if (! is_array($value)) {
            return ['' => $value];
        }

        if ($value === []) {
            return ['' => null];
        }

        if (! array_is_list($value)) {
            return $value;
        }

        $entries = [];
        foreach ($value as $row) {
            $entries[$row['name'].': total'] = $row['total'];
            $entries[$row['name'].': sesuai SLA'] = $row['compliant'];
            $entries[$row['name'].': persentase (%)'] = $row['rate'];
        }

        return $entries;
    }

    private static function scope(MetricDefinition $metric, ReportFilters $filters): string
    {
        $scope = self::periodScope($metric, $filters);

        if ($filters->packageCategoryId !== null && $metric->report === ReportType::Business && in_array($metric->key, self::NOT_BY_PACKAGE_CATEGORY, true)) {
            $scope .= '; Filter kategori paket tidak berlaku';
        }

        return $scope;
    }

    private static function periodScope(MetricDefinition $metric, ReportFilters $filters): string
    {
        if ($metric->dateBasis === DateBasis::Snapshot) {
            return 'Posisi saat ini: filter periode tidak berlaku';
        }

        return match (true) {
            $filters->dateFrom !== null && $filters->dateTo !== null => "Periode: {$filters->dateFrom} sampai {$filters->dateTo}",
            $filters->dateFrom !== null => "Periode: dari {$filters->dateFrom}",
            $filters->dateTo !== null => "Periode: sampai {$filters->dateTo}",
            default => 'Periode: tanpa rentang',
        };
    }
}
