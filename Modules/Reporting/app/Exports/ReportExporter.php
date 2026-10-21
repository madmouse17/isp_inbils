<?php

namespace Modules\Reporting\Exports;

use App\Models\Core\Location;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\AuditEventText;
use App\Support\AuditProperties;
use App\Support\ExportFormat;
use App\Support\ExportQuery;
use App\Support\RecordNotFound;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Modules\Inventory\Models\Product;
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
use Modules\Reporting\Queries\StockMovementReferences;
use Modules\Reporting\Registry\DateBasis;
use Modules\Reporting\Registry\MetricDefinition;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\MetricUnit;
use Modules\Reporting\Registry\ReportType;
use Modules\Reporting\Support\TimeBuckets;
use Modules\Service\Models\ServicePackageCategory;
use Modules\Ticketing\Models\TicketCategory;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV and PDF files of the reports, through the shared ExportQuery (BOM, formula-safe cells, row
 * cap with a visible marker). Aggregate reports export the same figures the screen shows, one row
 * per metric or breakdown entry; row-level reports export every row of the filtered view.
 *
 * A file speaks the screen's words (ExportLabels), never a code, an id or a raw unit. The CSV keeps one header row and
 * plain figures a spreadsheet can use; the PDF adds the company header, the period and the filters in words, and prints
 * each figure the way the screen does (Rp, dates, hours and minutes).
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
        $filters = $request->filters();
        $list = new DrillList($source, $filters, $request->user());
        // A file has no browser to read an offset: its moments are written in the company's own time.
        $timezone = TimeBuckets::timezoneOf($filters->companyId);

        $columns = [];
        $types = [];
        foreach ($kind->columns() as $column) {
            $columns[$column['label']] = $column['key'];

            if (($type = self::drillType($column['key'])) !== null) {
                $types[$column['label']] = $type;
            }
        }

        $map = static function (Model $model) use ($kind, $columns, $timezone): array {
            $row = $kind->row($model, $timezone);
            $mapped = [];
            foreach ($columns as $label => $key) {
                $mapped[$label] = ExportLabels::drillCell($kind, $key, $row[$key] ?? null);
            }

            return $mapped;
        };

        $title = $source->label.($request->input('group') ? ' - '.$request->input('group') : '');
        $stem = $request->report()->slug().'-'.str_replace('_', '-', $source->key);
        $export = ExportQuery::make($list->listing($request->sort(), $request->direction()))->maxRows(self::cap());

        return self::download(
            $export,
            $request->exportFormat(),
            $title,
            $columns,
            $map,
            $stem,
            types: $types,
            period: self::periodText($filters->dateFrom, $filters->dateTo),
            filters: self::filterPhrases($filters, $request->report(), withDates: false),
        );
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
        $names = $companies->pluck('name', 'public_id');

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
        // The PDF names the filters once, in its header, so its table leaves their column out.
        $shown = $request->exportFormat() === 'pdf' ? array_values(array_diff($headers, ['Filter'])) : $headers;
        $map = static fn (array $row): array => $row;

        return self::download(
            ExportQuery::fromRows($rows),
            $request->exportFormat(),
            $report->title(),
            array_combine($shown, $shown),
            $map,
            $report->slug(),
            types: ['Nilai' => static fn (array $row): ?string => $row['type']],
            period: self::periodText($filters->dateFrom, $filters->dateTo),
            filters: self::filterPhrases($filters, $report, withDates: false),
        );
    }

    private static function stockCard(ReportExportRequest $request): StreamedResponse
    {
        // The product is a record of the company: an unknown or foreign one answers like any record that is not there.
        $product = $request->record('product_id') ?? RecordNotFound::abort();
        $location = $request->record('location_id');
        $actor = $request->user();
        $ledger = StockCardQuery::ledger(
            (int) CompanyService::currentId(),
            $product['id'],
            $location['id'] ?? null,
            $request->dateFrom(),
            $request->dateTo(),
            (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'created_at'),
            (string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'desc'),
        )->with(['product:id,name', 'fromLocation:id,name', 'toLocation:id,name'])
            // The references of each chunk read in one query per type, so a row says "SPK SPK-2026-0001" and not a class and an id.
            ->afterQuery(function (EloquentCollection $movements) use ($actor): void {
                $references = StockMovementReferences::resolve($movements, $actor);

                foreach ($movements as $movement) {
                    $movement->setAttribute('reference_label', $references[$movement->id]['label'] ?? null);
                }
            });

        $columns = [
            'Tanggal' => 'date', 'Barang' => 'product', 'Jenis' => 'type', 'Jumlah' => 'quantity',
            'Saldo setelah' => 'balance_after', 'Dari' => 'from', 'Ke' => 'to', 'Referensi' => 'reference', 'Catatan' => 'note',
        ];
        // One zone on every surface: the company's, as the page shows it.
        $timezone = TimeBuckets::timezoneOf(null);
        $map = static fn (StockMovement $movement): array => [
            'Tanggal' => $movement->created_at?->copy()->setTimezone($timezone)->toDateTimeString(),
            'Barang' => $movement->product?->getAttribute('name'),
            'Jenis' => ExportLabels::movementType($movement->movement_type),
            'Jumlah' => $movement->quantity,
            'Saldo setelah' => $movement->balance_after,
            'Dari' => $movement->fromLocation?->getAttribute('name'),
            'Ke' => $movement->toLocation?->getAttribute('name'),
            'Referensi' => $movement->reference_type === null ? null : ($movement->getAttribute('reference_label') ?? StockMovementReferences::fallbackLabel($movement)),
            'Catatan' => $movement->note,
        ];

        $filters = ['Barang: '.Product::query()->withTrashed()->whereKey($product['id'])->value('name')];
        if ($location !== null) {
            $filters[] = 'Lokasi: '.Location::query()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($location['id'])->value('name');
        }

        return self::download(
            ExportQuery::make($ledger)->maxRows(self::cap()),
            $request->exportFormat(),
            ReportType::StockCard->title(),
            $columns,
            $map,
            ReportType::StockCard->slug(),
            types: ['Tanggal' => 'datetime', 'Jumlah' => 'number', 'Saldo setelah' => 'number'],
            period: self::periodText($request->input('date_from') ?: null, $request->input('date_to') ?: null),
            filters: $filters,
        );
    }

    private static function auditLog(ReportExportRequest $request): StreamedResponse
    {
        $listing = AuditLogQuery::listing(
            userId: $request->record('user_id')['id'] ?? null,
            logName: $request->input('log_name'),
            dateFrom: $request->dateFrom(),
            dateTo: $request->dateTo(),
            search: $request->input('search'),
            event: $request->input('event'),
            sortField: (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'created_at'),
            sortDirection: (string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'desc'),
        )->afterQuery(fn (EloquentCollection $activities) => AuditEventText::annotate($activities));

        // The PDF is read by a person, so it leaves out every id and code: ids, the raw action and the properties are for the CSV only.
        $withProperties = $request->exportFormat() === 'csv';
        $columns = $withProperties
            ? [
                'ID' => 'id', 'Waktu' => 'time', 'Modul' => 'module', 'Aksi' => 'event', 'Deskripsi' => 'description',
                'Pelaku' => 'actor', 'ID pelaku' => 'actor_id', 'Subjek' => 'subject', 'Jenis subjek' => 'subject_type', 'ID subjek' => 'subject_id',
                // Exactly the payload the screen shows to report.audit.view holders.
                'Properti' => 'properties',
            ]
            : ['Waktu' => 'time', 'Modul' => 'module', 'Deskripsi' => 'description', 'Pelaku' => 'actor', 'Subjek' => 'subject'];

        // One zone on every surface: the company's, as the page shows it.
        $timezone = TimeBuckets::timezoneOf(null);
        $map = static function (Activity $activity) use ($withProperties, $timezone): array {
            $causer = $activity->causer;
            $platformCauser = $causer instanceof PlatformAdmin;
            $row = [
                'ID' => $activity->public_id,
                'Waktu' => $activity->created_at?->copy()->setTimezone($timezone)->toDateTimeString(),
                'Modul' => ExportLabels::module($activity->log_name),
                'Aksi' => $activity->event,
                'Deskripsi' => AuditEventText::text($activity),
                'Pelaku' => $platformCauser ? 'Tim dukungan Inbils' : ($causer instanceof User ? $causer->name : ($activity->causer_id ? 'Pengguna dihapus' : 'Sistem')),
                'ID pelaku' => $causer instanceof User ? $causer->public_id : null,
                'Subjek' => $activity->getAttribute('subject_label'),
                'Jenis subjek' => $activity->subject_type,
                'ID subjek' => $activity->relationLoaded('subject') ? $activity->subject?->getAttribute('public_id') : null,
            ];

            if ($withProperties) {
                $properties = AuditProperties::forActivity($activity);
                $row['Properti'] = $properties === [] ? '' : json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            return $row;
        };

        $filters = [];
        if (($user = $request->record('user_id')) !== null) {
            $filters[] = 'Pengguna: '.User::query()->whereKey($user['id'])->value('name');
        }
        if (filled($request->input('log_name'))) {
            $filters[] = 'Modul: '.ExportLabels::module($request->input('log_name'));
        }
        if (filled($request->input('event'))) {
            $filters[] = 'Aksi: '.$request->input('event');
        }
        if (filled($request->input('search'))) {
            $filters[] = 'Pencarian: '.$request->input('search');
        }

        return self::download(
            ExportQuery::make($listing)->maxRows(self::cap()),
            $request->exportFormat(),
            ReportType::AuditLog->title(),
            $columns,
            $map,
            ReportType::AuditLog->slug(),
            types: ['Waktu' => 'datetime'],
            period: self::periodText($request->input('date_from') ?: null, $request->input('date_to') ?: null),
            filters: $filters,
        );
    }

    /**
     * @param  array<string, string>  $columns  header => key of the mapper result
     * @param  callable  $map  whole-row mapper returning values keyed by header
     * @param  array<string, string|callable>  $types  PDF only, ExportQuery::types(): how each column is printed
     * @param  list<string>  $filters  PDF only: what the header says about the filters, in words
     */
    private static function download(ExportQuery $export, string $format, string $title, array $columns, callable $map, string $stem, array $types = [], ?string $period = null, array $filters = []): StreamedResponse
    {
        $file = $stem.'-'.now()->format('Ymd-His');
        $export->types($types)->describe($period, $filters);

        return $format === 'pdf'
            ? $export->streamPdf($title, $columns, $map, $file.'.pdf')
            : $export->streamCsv($columns, $map, $file.'.csv');
    }

    private static function cap(): int
    {
        return (int) config('exports.max_rows', 5000);
    }

    /** How the PDF writes a column of a drill-down list, by the key of the row; a name or a status needs none. */
    private static function drillType(string $key): ?string
    {
        return match (true) {
            in_array($key, ['mrc_amount', 'total', 'paid_amount', 'outstanding'], true) => 'money',
            $key === 'issue_date' => 'date',
            str_ends_with($key, '_at'), str_ends_with($key, '_deadline') => 'datetime',
            in_array($key, ['resolution_minutes', 'first_response_minutes', 'work_minutes', 'score', 'customer_rating'], true) => 'number',
            default => null,
        };
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
        $filterText = implode('; ', self::filterPhrases($filters, $report)) ?: 'tidak ada';
        $currency = CompanyService::current()?->currency;

        $rows = [];
        foreach (MetricRegistry::forReport($report) as $metric) {
            $scope = self::scope($metric, $filters);

            foreach (self::entries($metric, $payload[$metric->key] ?? null) as [$detail, $value, $unit]) {
                $rows[] = self::row($metric->label, $detail, $value, $unit, $currency, $scope, $filterText, $metric->version);
            }
        }

        return $rows;
    }

    /**
     * One line of a summary file. `type` is no column: it tells the PDF how to write the figure of this line.
     *
     * @return array<string, string|int|float|null>
     */
    private static function row(string $metric, string $detail, mixed $value, MetricUnit $unit, ?string $currency, string $scope, string $filter, string $version): array
    {
        return [
            'Metrik' => $metric,
            'Rincian' => $detail,
            'Nilai' => $value,
            'Satuan' => ExportLabels::unit($unit, $currency),
            'Cakupan' => $scope,
            'Filter' => $filter,
            'Versi' => $version,
            'type' => match ($unit) {
                MetricUnit::Currency => 'money',
                MetricUnit::Percent => 'percent',
                MetricUnit::Minutes => 'duration',
                MetricUnit::Breakdown => null,
                default => 'number',
            },
        ];
    }

    /**
     * A figure as breakdown entries (detail, value, unit): a plain figure is one unnamed entry, a map one entry per
     * key (its asset status or SPK type in words), a list of rows (per category, handler, priority or target source) its
     * total, its on-time count and its rate, and its first response when the rows have one.
     *
     * @return list<array{0: string, 1: mixed, 2: MetricUnit}>
     */
    private static function entries(MetricDefinition $metric, mixed $value): array
    {
        if (! is_array($value)) {
            return [['', $value, $metric->unit]];
        }

        if ($value === []) {
            return [['', null, MetricUnit::Count]];
        }

        $entries = [];

        if (! array_is_list($value)) {
            foreach ($value as $key => $count) {
                $entries[] = [ExportLabels::detail($metric->key, (string) $key), $count, MetricUnit::Count];
            }

            return $entries;
        }

        foreach ($value as $row) {
            $name = ExportLabels::rowName($metric->key, $row['name']);
            $entries[] = [$name.': total', $row['total'], MetricUnit::Count];
            $entries[] = [$name.': sesuai SLA', $row['compliant'], MetricUnit::Count];
            $entries[] = [$name.': persentase (%)', $row['rate'], MetricUnit::Percent];

            if (array_key_exists('avg_frt_minutes', $row)) {
                $entries[] = [$name.': rata-rata respons pertama', $row['avg_frt_minutes'], MetricUnit::Minutes];
            }
        }

        return $entries;
    }

    private static function scope(MetricDefinition $metric, ReportFilters $filters): string
    {
        $scope = self::periodScope($metric->dateBasis, $filters);

        // A rating is dated by the day the customer gave it, not the day the ticket was resolved.
        if ($metric->dateColumn === 'tickets.customer_rated_at') {
            $scope .= '; menurut tanggal penilaian';
        }

        if ($filters->packageCategoryId !== null && $metric->report === ReportType::Business && in_array($metric->key, self::NOT_BY_PACKAGE_CATEGORY, true)) {
            $scope .= '; Filter kategori paket tidak berlaku';
        }

        return $scope;
    }

    private static function periodScope(DateBasis $basis, ReportFilters $filters): string
    {
        if ($basis === DateBasis::Snapshot) {
            return 'Posisi saat ini: filter periode tidak berlaku';
        }

        return match (true) {
            $filters->dateFrom !== null && $filters->dateTo !== null => "Periode: {$filters->dateFrom} sampai {$filters->dateTo}",
            $filters->dateFrom !== null => "Periode: dari {$filters->dateFrom}",
            $filters->dateTo !== null => "Periode: sampai {$filters->dateTo}",
            default => 'Periode: tanpa rentang',
        };
    }

    /**
     * The filters that apply to a report, as phrases a person reads: names where the screen holds an id (a technician, a
     * category, a location), never the id itself. The period is a filter of its own in the CSV and the header's own line
     * in the PDF, hence $withDates.
     *
     * @return list<string>
     */
    private static function filterPhrases(ReportFilters $filters, ReportType $report, bool $withDates = true): array
    {
        $phrases = [];

        foreach ($filters->query($report) as $key => $value) {
            $phrase = match ($key) {
                'date_from' => $withDates ? 'Tanggal mulai: '.$value : null,
                'date_to' => $withDates ? 'Tanggal akhir: '.$value : null,
                'technician_id' => 'Teknisi: '.User::query()->whereKey($filters->technicianId)->value('name'),
                'category_id' => 'Kategori: '.TicketCategory::query()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($filters->categoryId)->value('name'),
                'location_id' => 'Lokasi: '.Location::query()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($filters->locationId)->value('name'),
                'asset_type' => 'Jenis aset: '.ExportLabels::assetType($value),
                'service_package_category_id' => 'Kategori paket: '.ServicePackageCategory::query()->whereKey($filters->packageCategoryId)->value('name'),
                default => $key.': '.$value,
            };

            if ($phrase !== null) {
                $phrases[] = $phrase;
            }
        }

        return $phrases;
    }

    /** "1 Okt 2026 sampai 31 Okt 2026": the period of a file in the company's date format; null when it has no range. */
    private static function periodText(?string $from, ?string $to): ?string
    {
        $format = ExportFormat::forCompany(CompanyService::current());
        $from = $from === null ? null : $format->cell($from, 'date');
        $to = $to === null ? null : $format->cell($to, 'date');

        return match (true) {
            $from !== null && $to !== null => "{$from} sampai {$to}",
            $from !== null => "dari {$from}",
            $to !== null => "sampai {$to}",
            default => null,
        };
    }
}
