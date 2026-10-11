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

        $title = $source->label.($request->input('group') ? ' — '.$request->input('group') : '');
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
                $rows[] = ['Company' => $names[$row['company_id']], ...$line];
            }
        }
        foreach (self::summaryRows(ReportType::Business, $report['total'], $filters) as $line) {
            $rows[] = ['Company' => 'Consolidated', ...$line];
        }

        $headers = ['Company', 'Metric', 'Breakdown', 'Value', 'Unit', 'Scope', 'Filters', 'Version'];
        $map = static fn (array $row): array => $row;

        return self::download(ExportQuery::fromRows($rows), 'csv', 'Business Metrics (Consolidated)', array_combine($headers, $headers), $map, 'business-consolidated');
    }

    private static function summary(ReportExportRequest $request): StreamedResponse
    {
        $report = $request->report();
        $filters = $request->filters();
        $rows = self::summaryRows($report, $filters->payload($report), $filters);

        $headers = ['Metric', 'Breakdown', 'Value', 'Unit', 'Scope', 'Filters', 'Version'];
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
            'Date' => 'date', 'Product' => 'product', 'Type' => 'type', 'Quantity' => 'quantity',
            'Balance after' => 'balance_after', 'From' => 'from', 'To' => 'to', 'Reference' => 'reference', 'Note' => 'note',
        ];
        $map = static fn (StockMovement $movement): array => [
            'Date' => $movement->created_at?->toDateTimeString(),
            'Product' => $movement->product?->name,
            'Type' => $movement->movement_type,
            'Quantity' => $movement->quantity,
            'Balance after' => $movement->balance_after,
            'From' => $movement->fromLocation?->name,
            'To' => $movement->toLocation?->name,
            'Reference' => $movement->reference_type === null ? null : class_basename($movement->reference_type).' #'.$movement->reference_id,
            'Note' => $movement->note,
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
            'ID' => 'id', 'Time' => 'time', 'Module' => 'module', 'Event' => 'event', 'Description' => 'description',
            'Actor' => 'actor', 'Actor ID' => 'actor_id', 'Subject type' => 'subject_type', 'Subject ID' => 'subject_id',
        ];
        $withProperties = $request->exportFormat() === 'csv';
        if ($withProperties) {
            // Exactly the payload the screen shows to report.audit.view holders; the PDF leaves it out.
            $columns['Properties'] = 'properties';
        }

        $map = static function (Activity $activity) use ($withProperties): array {
            $causer = $activity->causer;
            $row = [
                'ID' => $activity->id,
                'Time' => $activity->created_at?->toDateTimeString(),
                'Module' => $activity->log_name,
                'Event' => $activity->event,
                'Description' => $activity->description,
                'Actor' => $causer instanceof User ? $causer->name : ($activity->causer_id ? 'User #'.$activity->causer_id : 'System'),
                'Actor ID' => $activity->causer_id,
                'Subject type' => $activity->subject_type,
                'Subject ID' => $activity->subject_id,
            ];

            if ($withProperties) {
                $properties = $activity->properties;
                $row['Properties'] = $properties?->isNotEmpty() ? json_encode($properties->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
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
            ? 'none'
            : implode('; ', array_map(fn (string $key, string|int $value): string => $key.'='.$value, array_keys($applied), $applied));

        $rows = [];
        foreach (MetricRegistry::forReport($report) as $metric) {
            foreach (self::entries($payload[$metric->key] ?? null) as $label => $value) {
                $rows[] = [
                    'Metric' => $metric->label,
                    'Breakdown' => (string) $label,
                    'Value' => $value,
                    'Unit' => $metric->unit->value,
                    'Scope' => self::scope($metric, $filters),
                    'Filters' => $filterText,
                    'Version' => $metric->version,
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
            $entries[$row['name'].': compliant'] = $row['compliant'];
            $entries[$row['name'].': rate (%)'] = $row['rate'];
        }

        return $entries;
    }

    private static function scope(MetricDefinition $metric, ReportFilters $filters): string
    {
        if ($metric->dateBasis === DateBasis::Snapshot) {
            return 'Snapshot: period filter not applied';
        }

        return $filters->dateFrom !== null && $filters->dateTo !== null
            ? "Period: {$filters->dateFrom} to {$filters->dateTo}"
            : 'Period: no complete range set';
    }
}
