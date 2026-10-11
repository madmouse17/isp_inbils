<?php

namespace Modules\Reporting\Drill;

use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Modules\Reporting\Queries\ReportFilters;
use Modules\Reporting\Registry\ReportType;

/**
 * One drill-down list: the records behind a figure of a report and how they tie out to it. The
 * rows come from the same Sources builders the aggregate reads, so the list is the figure.
 */
final readonly class DrillSource
{
    /**
     * @param  string  $key  URL key of the list; the payload/registry key unless $metric says otherwise.
     * @param  Closure(ReportFilters): Builder<*>  $query  Rows behind the figure.
     * @param  list<string>  $sum  Measure::Sum only: [column] or [column, minus] for sum(column) - sum(minus).
     * @param  (Closure(Builder<*>): Builder<*>)|null  $onTime  Measure::Share only: narrows the rows to the on-time ones.
     * @param  string|null  $metric  Key of the figure in the report payload and registry when it differs from $key.
     * @param  bool  $grouped  The list is one row of a breakdown; the request carries which one as `group`.
     * @param  string|null  $companion  Key of the sibling list whose rows complete the figure (workload = SPK + tickets).
     * @param  (Closure(ReportFilters, User): (array{label: string, href: string, amount: string}|null))|null  $reconcile  A surface that should show the same total.
     */
    public function __construct(
        public ReportType $report,
        public string $key,
        public string $label,
        public DrillKind $kind,
        public Closure $query,
        public Measure $measure = Measure::Count,
        public array $sum = [],
        public ?Closure $onTime = null,
        public ?string $metric = null,
        public bool $grouped = false,
        public ?string $companion = null,
        public ?Closure $reconcile = null,
    ) {}

    public function metric(): string
    {
        return $this->metric ?? $this->key;
    }

    /**
     * The figure the report shows for this list, out of its payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function figure(array $payload, ?string $group): mixed
    {
        $value = $payload[$this->metric()] ?? null;

        if (! $this->grouped || ! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            foreach ($value as $row) {
                if (($row['name'] ?? null) === $group) {
                    return $row['total'];
                }
            }

            return 0;
        }

        return $value[$group] ?? 0;
    }
}
