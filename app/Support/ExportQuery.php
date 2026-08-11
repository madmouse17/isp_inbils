<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    public const DEFAULT_MAX_ROWS = 100;

    private Builder $query;

    /** @var array<int|string, mixed> */
    private array $columns = [];

    private string $filename = 'export';

    private string $format = 'csv';

    private ?int $maxRows = null;

    private ?Request $request = null;

    private string $defaultSort = 'id';

    private string $defaultDirection = 'asc';

    private function __construct(Builder $query)
    {
        $this->query = $query;
    }

    public static function make(Builder $query): self
    {
        return new self($query);
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
        self::applySort($this->query, $request, $sortable, $defaultSort, $defaultDirection);
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
        self::applyListConstraints($this->query, $request, $searchable, $sortable, $defaultSort, $defaultDirection);
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
        $this->format = in_array($normalized, ['csv', 'xlsx', 'pdf'], true) ? $normalized : 'csv';

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

    public function sort(string $column, string $direction = 'asc'): self
    {
        $query = clone $this->query;
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
        // ponytail: title is kept for signature compatibility; plain-text PDF export does not render it yet.
        unset($title);

        return $this->columns($columns)->map(is_array($map) ? $map : $this->normalizeMap($map))->filename($filename)->streamPdfDownload();
    }

    public function toResponse(): StreamedResponse
    {
        return match ($this->resolveFormat()) {
            'xlsx' => $this->streamSpreadsheetDownload('xlsx'),
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

        return in_array($requested, ['csv', 'xlsx', 'pdf'], true) ? $requested : 'csv';
    }

    private function maxRowsLimit(): int
    {
        return self::resolveMaxRows($this->maxRows);
    }

    private function streamCsvDownload(): StreamedResponse
    {
        $columns = $this->columns;
        $headers = self::headerRow($columns);
        $filename = self::safeFilename($this->filename, 'csv');
        $query = clone $this->query;
        $max = $this->maxRowsLimit();

        return response()->streamDownload(function () use ($query, $columns, $headers, $max) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, $headers);

            $count = 0;
            foreach ($query->cursor() as $row) {
                if ($count >= $max) {
                    break;
                }
                fputcsv($out, array_map(self::escapeCsvCell(...), self::dataRow($row, $columns)));
                $count++;
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function streamSpreadsheetDownload(string $ext): StreamedResponse
    {
        // ponytail: xlsx is TSV download until a real spreadsheet writer is required
        $columns = $this->columns;
        $headers = self::headerRow($columns);
        $filename = self::safeFilename($this->filename, $ext);
        $query = clone $this->query;
        $max = $this->maxRowsLimit();
        $contentType = $ext === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/octet-stream';

        return response()->streamDownload(function () use ($query, $columns, $headers, $max) {
            echo implode("\t", $headers)."\n";
            $count = 0;
            foreach ($query->cursor() as $row) {
                if ($count >= $max) {
                    break;
                }
                $line = array_map(
                    static fn ($v) => str_replace(["\t", "\r", "\n"], ' ', (string) $v),
                    self::dataRow($row, $columns),
                );
                echo implode("\t", $line)."\n";
                $count++;
            }
        }, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    private function streamPdfDownload(): StreamedResponse
    {
        // ponytail: pdf is plain-text download until a PDF engine is required
        $columns = $this->columns;
        $headers = self::headerRow($columns);
        $filename = self::safeFilename($this->filename, 'pdf');
        $query = clone $this->query;
        $max = $this->maxRowsLimit();

        return response()->streamDownload(function () use ($query, $columns, $headers, $max) {
            echo implode(' | ', $headers)."\n";
            echo str_repeat('-', 80)."\n";
            $count = 0;
            foreach ($query->cursor() as $row) {
                if ($count >= $max) {
                    break;
                }
                echo implode(' | ', array_map(
                    static fn ($v) => str_replace(["\r", "\n"], ' ', (string) $v),
                    self::dataRow($row, $columns),
                ))."\n";
                $count++;
            }
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
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
        if (! in_array($ext, ['csv', 'xlsx', 'pdf'], true)) {
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

        if (preg_match('/^[=@]/', $text) === 1) {
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
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  array<int, string>|null  $header
     */
    public static function streamCsvFromRows(iterable $rows, string $filename, ?array $header = null): StreamedResponse
    {
        $filename = self::safeFilename($filename, 'csv');

        return response()->streamDownload(function () use ($rows, $header) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            $headerWritten = false;
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (! $headerWritten) {
                    fputcsv($out, $header ?? array_keys($row));
                    $headerWritten = true;
                }
                fputcsv($out, array_values($row));
            }

            if (! $headerWritten && $header !== null) {
                fputcsv($out, $header);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  callable|array<int|string, mixed>  $map
     * @return array<int|string, mixed>
     */
    private function normalizeMap(callable|array $map): array
    {
        if (is_array($map)) {
            return $map;
        }

        $normalized = [];
        foreach ($this->columns as $key => $resolver) {
            $normalized[$key] = $map;
        }

        return $normalized;
    }
}
