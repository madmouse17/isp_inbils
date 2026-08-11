<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Http\Requests\StoreUnitRequest;
use Modules\Inventory\Http\Requests\UpdateUnitRequest;
use Modules\Inventory\Http\Resources\UnitResource;
use Modules\Inventory\Models\Unit;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UnitController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'symbol', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Unit::class);

        $units = $this->filteredQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return Inertia::render('Admin/Inventory/Units/Index', [
            'units' => UnitResource::collection($units),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'can' => ['create' => $request->user()?->can('inventory.create') ?? false],
        ]);
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        Gate::authorize('store', Unit::class);
        Unit::create($request->validated());

        return back()->with('success', 'Unit created.');
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('update', $unit);
        $unit->update($request->validated());

        return back()->with('success', 'Unit updated.');
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        Gate::authorize('delete', $unit);

        if ($unit->products()->exists()) {
            return back()->withErrors(['unit' => 'Cannot delete unit with products.']);
        }

        $unit->delete();

        return back()->with('success', 'Unit deleted.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('inventory.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'name' => 'Name',
            'symbol' => 'Symbol',
        ];

        $map = static fn (Unit $u): array => [
            'name' => $u->name,
            'symbol' => $u->symbol,
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Units', $columns, $map, "units-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "units-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Unit>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Unit::query()
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('name', 'like', $like)
                        ->orWhere('symbol', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'name');
    }
}
