<?php

namespace Modules\Reporting\Drill;

use App\Models\User;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Reporting\Queries\ReportFilters;

/**
 * A drill-down list as one actor sees it: the source rows scoped to the company and to what the
 * actor may read, sorted and paged, plus the tie-out of those rows to the figure on the report.
 */
final readonly class DrillList
{
    /** The column a sum adds up, as the list and the report name it. */
    private const SUM_LABELS = [
        'mrc_amount' => 'MRC',
        'paid_amount' => 'terbayar',
        'total' => 'total',
    ];

    public function __construct(
        private DrillSource $source,
        private ReportFilters $filters,
        private User $actor,
    ) {}

    /**
     * The rows behind the figure that the actor may read.
     *
     * @return Builder<*>
     */
    public function query(): Builder
    {
        return $this->source->kind->scopeToActor(($this->source->query)($this->filters), $this->actor);
    }

    /**
     * The rows with their relations and order, ready to page or export.
     *
     * @return Builder<*>
     */
    public function listing(?string $sort, ?string $direction): Builder
    {
        $kind = $this->source->kind;
        [$defaultKey, $defaultDirection] = $kind->defaultSort();
        $columns = $kind->sortable();
        $sorted = $sort !== null && isset($columns[$sort]);
        $column = $columns[$sorted ? $sort : $defaultKey];
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : ($sorted ? 'asc' : $defaultDirection);

        $query = $this->query()->with($kind->with());
        $model = $query->getModel();

        return $query->orderBy($model->qualifyColumn($column), $direction)->orderBy($model->getQualifiedKeyName(), $direction);
    }

    public function paginate(?string $sort, ?string $direction, int $perPage): LengthAwarePaginator
    {
        $kind = $this->source->kind;

        return $this->listing($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Model $model): array => [...$kind->row($model), 'url' => $kind->url($model)]);
    }

    /**
     * How the rows compare with the figure the report shows. `matches` is null when rows alone
     * cannot prove it: an average, or a handler who only sees the rows assigned to them.
     *
     * @param  array<string, mixed>  $payload  The report payload for the same filters.
     * @return array<string, mixed>
     */
    public function tieOut(array $payload): array
    {
        $source = $this->source;
        $rows = $this->query()->count();
        $figure = $source->figure($payload, $this->filters->group);
        // A portal account's own-customer scope or a handler's own-rows scope only narrows a company-wide list; not on a handler's own performance, where the rows are the figure.
        $restricted = $source->kind->restrictsPortal($this->actor)
            || ($source->kind->restrictsHandlers($this->actor) && $this->filters->technicianId !== $this->actor->id);

        $tieOut = [
            'measure' => $source->measure->value,
            'noun' => $source->kind->noun(),
            'rows' => $rows,
            'figure' => $figure,
            'restricted' => $restricted,
            'matches' => null,
            'sum' => null,
            'sum_label' => null,
            'compliant' => null,
            'companion' => null,
        ];

        switch ($source->measure) {
            case Measure::Count:
                $companion = $this->companionRows();
                $tieOut['companion'] = $companion;
                $tieOut['matches'] = $restricted ? null : $rows + ($companion['rows'] ?? 0) === (int) $figure;
                break;

            case Measure::Sum:
                $sum = $this->sum();
                $tieOut['sum'] = $sum;
                $tieOut['sum_label'] = 'Σ '.implode(' − ', array_map(fn (string $column): string => self::SUM_LABELS[$column] ?? $column, $source->sum));
                $tieOut['matches'] = $restricted ? null : Money::compare($sum, (string) $figure) === 0;
                break;

            case Measure::Share:
                $compliant = ($source->onTime)($this->query())->count();
                $tieOut['compliant'] = $compliant;
                $percent = $rows > 0 ? round(($compliant / $rows) * 100, 1) : 0;
                $tieOut['matches'] = $restricted ? null : (float) $percent === (float) $figure;
                break;

            case Measure::Average:
                break;
        }

        return $tieOut;
    }

    /**
     * The surface that should show the same total, with the difference to this list's sum.
     *
     * @param  array<string, mixed>  $tieOut
     * @return array{label: string, href: string, amount: string, difference: string}|null
     */
    public function reconciliation(array $tieOut): ?array
    {
        $reconcile = $this->source->reconcile;
        $target = $reconcile === null ? null : $reconcile($this->filters, $this->actor);

        if ($target === null || $tieOut['sum'] === null) {
            return null;
        }

        return [...$target, 'difference' => Money::round(Money::sub($tieOut['sum'], $target['amount']))];
    }

    private function sum(): string
    {
        [$plus, $minus] = [...$this->source->sum, null];
        $total = (string) $this->query()->sum($plus);

        if ($minus !== null) {
            $total = Money::sub($total, (string) $this->query()->sum($minus));
        }

        return Money::round($total);
    }

    /** @return array{label: string, rows: int}|null */
    private function companionRows(): ?array
    {
        $companion = $this->source->companion === null
            ? null
            : DrillRegistry::find($this->source->report, $this->source->companion);

        return $companion === null
            ? null
            : ['label' => $companion->kind->noun(), 'rows' => (new self($companion, $this->filters, $this->actor))->query()->count()];
    }
}
