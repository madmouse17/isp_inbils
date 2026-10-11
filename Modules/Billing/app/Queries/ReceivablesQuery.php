<?php

namespace Modules\Billing\Queries;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Billing\Services\BillingService;

class ReceivablesQuery
{
    private const SORTABLE = ['customer', 'current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus', 'total', 'invoice_count'];

    /** @return array{rows: LengthAwarePaginator, totals: array<string, string|int>, filters: array<string, string|int>} */
    public function run(Request $request): array
    {
        $rows = collect(BillingService::receivables());
        $totals = $rows->reduce(function (array $totals, array $row): array {
            foreach (['current', 'b1_30', 'b31_60', 'b61_90', 'b90_plus', 'total', 'invoice_count'] as $key) {
                $totals[$key] = $key === 'invoice_count'
                    ? $totals[$key] + $row[$key]
                    : Money::add($totals[$key], (string) $row[$key]);
            }

            return $totals;
        }, ['current' => '0.00', 'b1_30' => '0.00', 'b31_60' => '0.00', 'b61_90' => '0.00', 'b90_plus' => '0.00', 'total' => '0.00', 'invoice_count' => 0]);

        $sortBy = $request->query('sort_by', $request->query('sort', 'customer'));
        $sortBy = is_string($sortBy) && in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'customer';
        $sortDir = $request->query('sort_dir', $request->query('direction')) === 'desc' ? 'desc' : 'asc';
        $rows = $rows->sort(function (array $a, array $b) use ($sortBy, $sortDir): int {
            $comparison = is_int($a[$sortBy]) && is_int($b[$sortBy])
                ? $a[$sortBy] <=> $b[$sortBy]
                : (is_numeric($a[$sortBy]) && is_numeric($b[$sortBy])
                    ? Money::compare((string) $a[$sortBy], (string) $b[$sortBy])
                    : strcasecmp((string) $a[$sortBy], (string) $b[$sortBy]));

            return $sortDir === 'desc' ? -$comparison : $comparison;
        })->values();

        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $page = max(1, (int) $request->query('page', 1));

        return [
            'rows' => new LengthAwarePaginator(
                $rows->forPage($page, $perPage)->values(),
                $rows->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'totals' => $totals,
            'filters' => ['sort_by' => $sortBy, 'sort_dir' => $sortDir, 'per_page' => $perPage],
        ];
    }
}
