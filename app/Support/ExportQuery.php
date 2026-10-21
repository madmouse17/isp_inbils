<?php

namespace App\Support;

use App\Models\Core\Company;
use App\Services\Core\AuditService;
use App\Services\Core\CompanyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\FontMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared export helper for Pattern B tables.
 *
 * Fluent:
 *   ExportQuery::make($query)
 *       ->defaultSort('name', 'asc')
 *       ->maxRows(5000)
 *       ->fromRequest($request)
 *       ->streamCsv([...], fn (...) => [...], 'things.csv');
 */
class ExportQuery
{
    private const PDF_MAX_CELLS = 1500;

    public const DEFAULT_MAX_ROWS = 100;

    private const TRUNCATION_NOTICE = '# Ekspor dipotong pada %d baris. Persempit filter untuk mengekspor sisa barisnya.';

    /** Eloquent source, or already materialised rows (aggregate summaries keyed by column header). */
    private Builder|array $query;

    /** @var array<int|string, mixed> */
    private array $columns = [];

    private string $filename = 'export';

    private string $title = 'Export';

    private string $format = 'csv';

    private ?int $maxRows = null;

    private ?Request $request = null;

    private string $defaultSort = 'id';

    private string $defaultDirection = 'asc';

    /** @var array<string, string|callable> PDF only: the type of a column by header, or a function of the source row that names it. */
    private array $types = [];

    private ?string $period = null;

    /** @var list<string> */
    private array $filterLines = [];

    /** @param  Builder<*>|list<array<string, mixed>>  $query */
    private function __construct(Builder|array $query)
    {
        $this->query = $query;
    }

    public static function make(Builder $query): self
    {
        return new self($query);
    }

    /**
     * Export of rows that are not an Eloquent query. Shares the CSV (BOM, formula escaping) and
     * PDF paths with make(); sorting and list constraints apply to queries only.
     *
     * @param  list<array<string, mixed>>  $rows  keyed by column header
     */
    public static function fromRows(array $rows): self
    {
        return new self($rows);
    }

    public static function resolveMaxRows(?int $override = null): int
    {
        if (is_int($override) && $override > 0) {
            return $override;
        }

        return self::DEFAULT_MAX_ROWS;
    }

    /**
     * @param  array<int, string>  $sortable
     */
    public function forRequest(
        Request $request,
        array $sortable,
        string $defaultSort = 'id',
        string $defaultDirection = 'asc',
    ): self {
        self::applySort($this->builder(), $request, $sortable, $defaultSort, $defaultDirection);
        $this->request = $request;

        return $this;
    }

    /**
     * Legacy alias used by controllers.
     *
     * @param  array<int, string>  $sortable
     * @param  array<int, string>  $searchable
     */
    public function for(
        Request $request,
        array $sortable,
        array $searchable = [],
        string $defaultSort = 'id',
        string $defaultDirection = 'asc',
    ): self {
        self::applyListConstraints($this->builder(), $request, $searchable, $sortable, $defaultSort, $defaultDirection);
        $this->request = $request;

        return $this;
    }

    /**
     * Legacy alias used by controllers.
     *
     * @param  array<int, string>  $sortable
     */
    public function sortable(array $sortable): self
    {
        unset($sortable);

        return $this;
    }

    /**
     * Legacy alias used by controllers.
     *
     * @param  array<int|string, mixed>  $columns
     * @param  callable|array<int|string, mixed>  $map
     */
    public function downloadPdf(string $title, array $columns, callable|array $map, string $filename): StreamedResponse
    {
        return $this->streamPdf($title, $columns, $map, $filename);
    }

    /**
     * @param  array<int, string>  $searchable
     * @param  array<int, string>  $sortable
     */
    public static function applyListConstraints(
        Builder $query,
        Request $request,
        array $searchable = [],
        array $sortable = [],
        string $defaultSort = 'id',
        string $defaultDirection = 'asc',
    ): Builder {
        $term = trim((string) ($request->input('q') ?? $request->input('search') ?? ''));
        $columns = array_values(array_filter(
            $searchable !== [] ? $searchable : $sortable,
            static fn ($c) => is_string($c) && $c !== '',
        ));

        if ($term !== '' && $columns !== []) {
            $query->where(function (Builder $q) use ($term, $columns) {
                foreach ($columns as $i => $column) {
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $q->{$method}($column, 'like', '%'.$term.'%');
                }
            });
        }

        return self::applySort($query, $request, $sortable, $defaultSort, $defaultDirection);
    }

    /** @param  array<int|string, mixed>  $columns */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /** @param  array<int|string, mixed>  $columns */
    public function map(array $columns): self
    {
        return $this->columns($columns);
    }

    public function filename(string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function format(string $format): self
    {
        $normalized = strtolower(trim($format));
        $this->format = in_array($normalized, ['csv', 'pdf'], true) ? $normalized : 'csv';

        return $this;
    }

    public function defaultSort(string $column, string $direction = 'asc'): self
    {
        $this->defaultSort = $column;
        $this->defaultDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    public function fromRequest(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function maxRows(?int $rows): self
    {
        $this->maxRows = self::resolveMaxRows($rows);

        return $this;
    }

    /**
     * PDF only, the CSV keeps its raw values: how the cells of a column are printed, by column header. A type is one of
     * ExportFormat::TYPES; a function of the source row may name it instead, for a column that holds different kinds of
     * figure (and is aligned as one). A column without a type prints as it is.
     *
     * @param  array<string, string|callable(mixed): ?string>  $types
     */
    public function types(array $types): self
    {
        $this->types = $types;

        return $this;
    }

    /**
     * PDF only: what the header says about the data besides the report's title, already in words (no ids): the period
     * and one phrase per filter that is applied.
     *
     * @param  list<string>  $filters
     */
    public function describe(?string $period = null, array $filters = []): self
    {
        $this->period = $period;
        $this->filterLines = $filters;

        return $this;
    }

    public function sort(string $column, string $direction = 'asc'): self
    {
        $query = clone $this->builder();
        $this->query = $query->reorder()->orderBy($column, strtolower($direction) === 'desc' ? 'desc' : 'asc');

        return $this;
    }

    /**
     * Legacy wrapper used by controllers.
     *
     * @param  array<int|string, mixed>  $columns
     * @param  callable|array<int|string, mixed>  $map
     */
    public function streamCsv(array $columns, callable|array $map, string $filename): StreamedResponse
    {
        return $this->columns($columns)->map(is_array($map) ? $map : $this->normalizeMap($map))->filename($filename)->streamCsvDownload();
    }

    /**
     * Legacy wrapper used by controllers.
     *
     * @param  array<int|string, mixed>  $columns
     * @param  callable|array<int|string, mixed>  $map
     */
    public function streamPdf(string $title, array $columns, callable|array $map, string $filename): StreamedResponse
    {
        $this->title = $title;

        return $this->columns($columns)->map(is_array($map) ? $map : $this->normalizeMap($map))->filename($filename)->streamPdfDownload();
    }

    public function toResponse(): StreamedResponse
    {
        return match ($this->resolveFormat()) {
            'pdf' => $this->streamPdfDownload(),
            default => $this->streamCsvDownload(),
        };
    }

    private function resolveFormat(): string
    {
        if ($this->format !== 'csv') {
            return $this->format;
        }

        $request = $this->request ?? request();
        $requested = strtolower((string) $request->query('format', 'csv'));

        return in_array($requested, ['csv', 'pdf'], true) ? $requested : 'csv';
    }

    private function maxRowsLimit(): int
    {
        return self::resolveMaxRows($this->maxRows);
    }

    /** @return Builder<*> */
    private function builder(): Builder
    {
        if (! $this->query instanceof Builder) {
            throw new \LogicException('Sorting and list constraints need an Eloquent query.');
        }

        return $this->query;
    }

    /**
     * cursor() skips eager loading, which would turn every relation into one query per row;
     * lazy() chunks the query and keeps with().
     *
     * @return iterable<int, mixed>
     */
    private function rows(): iterable
    {
        if (is_array($this->query)) {
            return $this->query;
        }

        $query = clone $this->query;
        if ($query->getEagerLoads() === []) {
            return $query->cursor();
        }

        // lazy() pages with LIMIT/OFFSET: a unique final order keeps chunks from repeating or dropping ties.
        $key = $query->getModel()->getQualifiedKeyName();
        $orderedColumns = array_column($query->getQuery()->orders ?? [], 'column');
        if (! in_array($key, $orderedColumns, true) && ! in_array($query->getModel()->getKeyName(), $orderedColumns, true)) {
            $query->orderBy($key);
        }

        return $query->lazy(500);
    }

    private function isTruncated(int $max): bool
    {
        if (is_array($this->query)) {
            return count($this->query) > $max;
        }

        return (clone $this->query)->toBase()->offset($max)->limit(1)->exists();
    }

    /** @return array<string, string> */
    private function downloadHeaders(string $contentType, int $max): array
    {
        return [
            'Content-Type' => $contentType,
            'X-Export-Truncated' => $this->isTruncated($max) ? '1' : '0',
            'X-Export-Row-Cap' => (string) $max,
        ];
    }

    private function streamCsvDownload(): StreamedResponse
    {
        $columns = $this->columns;
        $headers = self::headerRow($columns);
        $filename = self::safeFilename($this->filename, 'csv');
        $max = $this->maxRowsLimit();

        return response()->streamDownload(function () use ($columns, $headers, $max) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);

            $count = 0;
            $truncated = false;
            foreach ($this->rows() as $row) {
                if ($count >= $max) {
                    fputcsv($out, [sprintf(self::TRUNCATION_NOTICE, $max)]);
                    $truncated = true;
                    break;
                }
                fputcsv($out, array_map(self::escapeCsvCell(...), self::dataRow($row, $columns)));
                $count++;
            }

            fclose($out);
            $this->auditExport('csv', $count, $truncated);
        }, $filename, $this->downloadHeaders('text/csv; charset=UTF-8', $max));
    }

    private function streamPdfDownload(): StreamedResponse
    {
        $title = $this->title;
        $columns = $this->columns;
        $headers = self::headerRow($columns);
        $filename = self::safeFilename($this->filename, 'pdf');
        // DomPDF holds the whole table in memory (F9-D01): the cap counts cells, so wide tables get fewer rows.
        $max = min($this->maxRowsLimit(), intdiv(self::PDF_MAX_CELLS, max(1, count($headers))));

        return response()->streamDownload(function () use ($headers, $max, $title, $columns) {
            $company = CompanyService::current();
            $format = ExportFormat::forCompany($company);
            $rows = [];
            $capped = false;
            foreach ($this->rows() as $row) {
                if (count($rows) >= $max) {
                    $capped = true;
                    break;
                }
                $rows[] = $this->pdfCells($row, $columns, $headers, $format);
            }

            $pdf = Pdf::loadView('exports.table', [
                'title' => $title,
                'headers' => $headers,
                'rows' => $rows,
                'capped' => $capped,
                'maxRows' => $max,
                'figures' => array_keys(array_filter($this->types, fn (mixed $type): bool => ! is_string($type) || in_array($type, ExportFormat::FIGURES, true))),
                'company' => ['name' => $company?->name, 'logo' => self::logoOf($company)],
                'period' => $this->period,
                'filters' => $this->filterLines,
                'printedAt' => $format->now(),
                'printedBy' => ($this->request ?? request())->user()?->name,
            ])->setPaper('a4', 'landscape')
                ->setOption('isRemoteEnabled', false)
                // The package turns subsetting off, which embeds the whole DejaVu font (about 880 KB) in every file.
                ->setOption('isFontSubsettingEnabled', true);
            $pdf->getDomPDF()->setCallbacks(['pageNumbers' => ['event' => 'end_document', 'f' => self::pageNumber(...)]]);

            echo $pdf->output();
            $this->auditExport('pdf', count($rows), $capped);
        }, $filename, $this->downloadHeaders('application/pdf', $max));
    }

    /**
     * One table row of the PDF, keyed by header: the declared type of each column applied, the raw value kept otherwise.
     *
     * @param  array<int|string, mixed>  $columns
     * @param  array<int, string>  $headers
     * @return array<string, string|int|float>
     */
    private function pdfCells(mixed $row, array $columns, array $headers, ExportFormat $format): array
    {
        $cells = array_combine($headers, self::dataRow($row, $columns));

        foreach ($this->types as $header => $type) {
            // A name is a string ("date" is also a PHP function, so is_callable() cannot tell them apart); anything else is asked.
            $type = is_string($type) ? $type : $type($row);

            if ($type !== null && isset($cells[$header])) {
                $cells[$header] = $format->cell($cells[$header], $type);
            }
        }

        return $cells;
    }

    /** "Halaman 2 dari 5" at the foot of every page, drawn once the page count is known (no inline PHP in the view). */
    private static function pageNumber(int $page, int $pages, Canvas $canvas, FontMetrics $fonts): void
    {
        $font = $fonts->getFont('DejaVu Sans');
        $text = "Halaman {$page} dari {$pages}";
        $size = 8.0;

        $canvas->text($canvas->get_width() - 32 - $fonts->getTextWidth($text, $font, $size), $canvas->get_height() - 28, $text, $font, $size, [0.4, 0.4, 0.4]);
    }

    /** The company's logo as a data URI the PDF can embed without fetching anything; null when it has none or the file is gone. */
    private static function logoOf(?Company $company): ?string
    {
        $logo = $company?->getFirstMedia('logo');
        $bytes = $logo === null ? null : Storage::disk($logo->disk)->get($logo->getPathRelativeToRoot());

        return $bytes === null ? null : 'data:'.$logo->mime_type.';base64,'.base64_encode($bytes);
    }

    /** One row per finished download; an aborted stream never reaches it. */
    private function auditExport(string $format, int $rows, bool $truncated): void
    {
        $request = $this->request ?? request();

        AuditService::log('export', 'export.'.$format, [
            'report' => $request->route()?->getName(),
            'filters' => self::maskSearchTerms(Arr::except($request->query(), ['format'])),
            'rows' => $rows,
            'truncated' => $truncated,
        ]);
    }

    /**
     * A search term is often a phone number, an email or a name: the row says that one was typed, never what.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function maskSearchTerms(array $filters): array
    {
        foreach (['q', 'search'] as $key) {
            if (filled($filters[$key] ?? null)) {
                $filters[$key] = AuditProperties::MASKED;
            }
        }

        return $filters;
    }

    /** @param array<int|string, mixed> $columns @return array<int, string> */
    private static function headerRow(array $columns): array
    {
        if ($columns === []) {
            return [];
        }

        if (array_is_list($columns)) {
            return array_map(static fn ($c) => is_string($c) ? $c : (string) $c, $columns);
        }

        return array_map(static fn ($c) => (string) $c, array_keys($columns));
    }

    /** @param array<int|string, mixed> $columns @return array<int, mixed> */
    private static function dataRow(mixed $row, array $columns): array
    {
        if ($columns === []) {
            return [];
        }

        if (array_is_list($columns)) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = is_string($column) ? self::cellValue($row, $column) : '';
            }

            return $line;
        }

        $line = [];
        foreach ($columns as $label => $resolver) {
            if (is_callable($resolver) || (is_string($resolver) && $resolver !== '')) {
                $line[] = self::cellValue($row, is_string($label) ? $label : (string) $label, $resolver);
            } else {
                $line[] = self::cellValue($row, (string) $label);
            }
        }

        return $line;
    }

    public static function safeFilename(string $name, string $ext = 'csv'): string
    {
        $normalized = str_replace([chr(92), '/'], DIRECTORY_SEPARATOR, $name);
        $base = pathinfo($normalized, PATHINFO_FILENAME);
        $base = Str::slug((string) $base) ?: 'export';
        $ext = ltrim(strtolower($ext), '.');
        if (! in_array($ext, ['csv', 'pdf'], true)) {
            $ext = 'csv';
        }

        return $base.'.'.$ext;
    }

    /**
     * Resolve a cell for export.
     *
     * @param  callable|string|null  $resolver
     */
    public static function cellValue(mixed $row, ?string $column = null, mixed $resolver = null): string|int|float
    {
        if ($column === null && $resolver === null && ! is_array($row) && ! is_object($row)) {
            return self::stringify($row);
        }

        if (is_callable($resolver)) {
            return self::stringify($resolver($row));
        }

        if (is_string($resolver) && $resolver !== '') {
            return self::stringify(data_get($row, $resolver));
        }

        if (is_string($column) && $column !== '') {
            return self::stringify(data_get($row, $column));
        }

        return self::stringify($row);
    }

    private static function stringify(mixed $value): string|int|float
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '' : $encoded;
        }
        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return (string) $value;
            }
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '';
        }

        return (string) $value;
    }

    private static function escapeCsvCell(mixed $value): string|int|float
    {
        $text = self::stringify($value);

        if (! is_string($text) || $text === '') {
            return $text;
        }

        if (preg_match('/^[=@\t\r]/', $text) === 1) {
            return "'".$text;
        }

        if (($text[0] === '+' || $text[0] === '-') && ! preg_match('/^[+-]\d+(?:[.,]\d+)?$/', $text)) {
            return "'".$text;
        }

        return $text;
    }

    /**
     * @param  array<int, string>  $sortable
     */
    public static function applySort(
        Builder $query,
        Request $request,
        array $sortable,
        string $default = 'id',
        string $defaultDir = 'asc',
    ): Builder {
        $sortable = array_values(array_unique(array_filter($sortable, 'is_string')));

        $sort = (string) ($request->input('sort') ?? $request->input('sort_by') ?? $default);
        if (! in_array($sort, $sortable, true)) {
            $sort = in_array($default, $sortable, true) ? $default : ($sortable[0] ?? 'id');
        }

        $dir = strtolower((string) ($request->input('direction') ?? $request->input('sort_dir') ?? $defaultDir));
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = in_array(strtolower($defaultDir), ['asc', 'desc'], true) ? strtolower($defaultDir) : 'asc';
        }

        $from = $query->getQuery()->from;
        $column = is_string($from) && $from !== '' && ! str_contains($sort, '.')
            ? $from.'.'.$sort
            : $sort;

        return $query->orderBy($column, $dir);
    }

    /**
     * @param  array<string, callable(mixed): mixed|string>  $columns
     * @return Collection<int, array<string, mixed>>
     */
    public static function mapRows(iterable $rows, array $columns): Collection
    {
        return collect($rows)->map(function ($row) use ($columns) {
            $out = [];
            foreach ($columns as $key => $resolver) {
                $out[$key] = is_callable($resolver) ? $resolver($row) : data_get($row, $resolver);
            }

            return $out;
        });
    }

    /**
     * Expand a whole-row mapper into per-column resolvers keyed by header label.
     * The mapper runs once per row; columns read from the memoized result.
     *
     * Columns arrive in two shapes, both keyed by the mapper's output key:
     * `'field' => 'Header'` and `'Header' => 'data.path'`. A value that looks
     * like a data path keeps the key as the header.
     *
     * @param  callable|array<int|string, mixed>  $map
     * @return array<int|string, mixed>
     */
    private function normalizeMap(callable|array $map): array
    {
        if (is_array($map)) {
            return $map;
        }

        $lastRow = null;
        $lastValues = [];
        $resolve = static function (mixed $row) use ($map, &$lastRow, &$lastValues): array {
            if ($row !== $lastRow) {
                $lastRow = $row;
                $lastValues = (array) $map($row);
            }

            return $lastValues;
        };

        $normalized = [];
        foreach ($this->columns as $key => $label) {
            $field = is_string($key) ? $key : (string) $label;
            $isDataPath = ! is_string($label) || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $label) === 1;
            $header = is_string($key) && $isDataPath ? $key : (string) $label;
            $normalized[$header] = static fn (mixed $row): mixed => $resolve($row)[$field] ?? null;
        }

        return $normalized;
    }
}
