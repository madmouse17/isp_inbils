<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreVehicleRequest;
use App\Http\Requests\Admin\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\Core\Vehicle;
use App\Services\Core\LifecycleService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VehicleController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['plate_number', 'type', 'brand', 'model', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Vehicle::class);

        $vehicles = $this->filteredQuery($request)->withReferenceCounts();

        if (! $request->filled('sort') && ! $request->filled('sort_by')) {
            $vehicles->reorder()->latest();
        }

        $vehicles = $vehicles->paginate($this->perPage($request))->withQueryString();
        $canManage = $request->user()?->can('vehicle.manage') ?? false;

        return Inertia::render('Admin/Vehicles/Index', [
            'vehicles' => VehicleResource::collection($vehicles),
            'filters' => $request->only(['status', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => $canManage,
                'update' => $canManage,
                'delete' => $canManage,
                'export' => $request->user()?->can('vehicle.export') ?? false,
            ],
        ]);
    }

    public function store(StoreVehicleRequest $request): RedirectResponse
    {
        Gate::authorize('create', Vehicle::class);
        Vehicle::create($request->validated());

        return back()->with('success', 'Kendaraan dibuat.');
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('update', $vehicle);
        $vehicle->update($request->validated());

        return back()->with('success', 'Kendaraan diperbarui.');
    }

    public function destroy(Vehicle $vehicle): RedirectResponse
    {
        Gate::authorize('delete', $vehicle);
        LifecycleService::destroyIfUnreferenced($vehicle);

        return back()->with('success', 'Kendaraan dihapus permanen.');
    }

    public function export(Request $request): Response|StreamedResponse
    {
        Gate::authorize('vehicle.export');

        $format = strtolower((string) $request->input('format', 'csv'));
        $stamp = now()->format('Ymd-His');
        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('plate_number', 'asc')
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'plate_number' => 'Nomor polisi',
            'type' => 'Jenis',
            'brand' => 'Merek',
            'model' => 'Model',
            'is_active' => 'Status',
        ];

        $map = static fn (Vehicle $v): array => [
            'plate_number' => $v->plate_number,
            'type' => $v->type ?? '',
            'brand' => $v->brand ?? '',
            'model' => $v->model ?? '',
            'is_active' => $v->is_active ? 'Aktif' : 'Nonaktif',
        ];

        if ($format === 'pdf') {
            return $export->streamPdf('Kendaraan', $columns, $map, "vehicles-export-{$stamp}.pdf");
        }

        return $export->streamCsv($columns, $map, "vehicles-export-{$stamp}.csv");
    }

    /**
     * @return Builder<Vehicle>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Vehicle::query()
            ->lifecycleStatus($request->input('status'))
            ->when($request->input('search'), function (Builder $q, string $v): void {
                $term = trim($v);
                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $q->where(function (Builder $sq) use ($like): void {
                    $sq->where('plate_number', 'like', $like)
                        ->orWhere('type', 'like', $like)
                        ->orWhere('brand', 'like', $like)
                        ->orWhere('model', 'like', $like);
                });
            });

        return $this->applySort($query, $request, 'plate_number');
    }
}
