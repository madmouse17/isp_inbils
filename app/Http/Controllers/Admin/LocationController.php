<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveLocationRequest;
use App\Http\Requests\Admin\StoreLocationRequest;
use App\Http\Requests\Admin\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Core\Location;
use App\Services\Core\LifecycleService;
use App\Services\Core\LocationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'name', 'type', 'path', 'is_active', 'created_at'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Location::class);

        $query = $this->filteredQuery($request);

        $locationsTable = (clone $query)->withReferenceCounts()->paginate($this->perPage($request))->withQueryString();

        $markers = (clone $query)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get(['id', 'public_id', 'code', 'name', 'type', 'lat', 'lng', 'is_active'])
            ->map(fn (Location $location): array => [
                'id' => $location->public_id,
                'code' => $location->code,
                'name' => $location->name,
                'type' => $location->type,
                'lat' => $location->lat,
                'lng' => $location->lng,
                'is_active' => $location->is_active,
            ])
            ->values();

        return Inertia::render('Admin/Locations/Index', [
            'locations' => LocationResource::collection($locationsTable),
            'tree' => LocationResource::collection(LocationService::tree()),
            'markers' => $markers,
            'filters' => $this->effectiveFilters($request),
            'can' => [
                'create' => $request->user()?->can('location.create') ?? false,
                'update' => $request->user()?->can('location.update') ?? false,
                'move' => $request->user()?->can('location.move') ?? false,
                'delete' => $request->user()?->can('location.delete') ?? false,
            ],
        ]);
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        Gate::authorize('store', Location::class);

        LocationService::create($request->validated());

        return back()->with('success', 'Lokasi dibuat.');
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        Gate::authorize('update', $location);

        LocationService::update($location, $request->validated());

        return back()->with('success', 'Lokasi diperbarui.');
    }

    public function move(MoveLocationRequest $request, Location $location): RedirectResponse
    {
        LocationService::move($location, (int) $request->validated('new_parent_id'));

        return back()->with('success', 'Lokasi dipindahkan.');
    }

    public function destroy(Location $location): RedirectResponse
    {
        Gate::authorize('delete', $location);

        LifecycleService::destroyIfUnreferenced($location);

        return back()->with('success', 'Lokasi dihapus permanen.');
    }

    /**
     * @return Builder<Location>
     */
    private function filteredQuery(Request $request): Builder
    {
        $query = Location::query()
            ->lifecycleStatus($this->status($request))
            ->when($this->scalarInput($request, 'search'), function (Builder $query, string $value): void {
                $term = trim($value);

                if ($term === '') {
                    return;
                }

                $like = '%'.$term.'%';
                $query->where(function (Builder $subQuery) use ($like): void {
                    $subQuery->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('path', 'like', $like)
                        ->orWhere('address', 'like', $like);
                });
            })
            ->when($this->scalarInput($request, 'type'), function (Builder $query, string $value): void {
                if (in_array($value, ['region', 'area', 'pop', 'rack', 'site'], true)) {
                    $query->where('type', $value);
                }
            });

        $sort = $this->scalarInput($request, 'sort_by') ?? $this->scalarInput($request, 'sort');
        $sort = is_string($sort) && in_array($sort, self::SORTABLE, true) ? $sort : 'path';

        $rawDir = $this->scalarInput($request, 'sort_dir') ?? $this->scalarInput($request, 'direction') ?? $this->scalarInput($request, 'dir');
        $dir = is_string($rawDir) && strtolower($rawDir) === 'desc' ? 'desc' : 'asc';

        return $query->orderBy($sort, $dir);
    }

    private function scalarInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : null;
    }

    /** The `status` query value when it names a view of the page, else null: the default view. */
    private function status(Request $request): ?string
    {
        $status = strtolower(trim((string) $this->scalarInput($request, 'status')));

        return in_array($status, [...array_keys(Location::lifecycleFilters()), 'dihapus', 'semua'], true) ? $status : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveFilters(Request $request): array
    {
        $search = $this->scalarInput($request, 'search');
        $rawType = $this->scalarInput($request, 'type');
        $type = in_array($rawType, ['region', 'area', 'pop', 'rack', 'site'], true) ? $rawType : null;

        $status = $this->status($request);

        $rawSort = $this->scalarInput($request, 'sort_by') ?? $this->scalarInput($request, 'sort');
        $sort = is_string($rawSort) && in_array($rawSort, self::SORTABLE, true) ? $rawSort : null;

        $rawDir = $this->scalarInput($request, 'sort_dir') ?? $this->scalarInput($request, 'direction') ?? $this->scalarInput($request, 'dir');
        $direction = null;
        if ($rawDir !== null) {
            $direction = strtolower($rawDir) === 'desc' ? 'desc' : 'asc';
        }

        $rawPerPage = $this->scalarInput($request, 'per_page');
        $perPage = null;
        if ($rawPerPage !== null && is_numeric($rawPerPage)) {
            $intPerPage = (int) $rawPerPage;
            if (in_array($intPerPage, [10, 25, 50], true)) {
                $perPage = $intPerPage;
            }
        }

        $rawPage = $this->scalarInput($request, 'page');
        $page = ($rawPage !== null && is_numeric($rawPage) && (int) $rawPage > 0) ? (int) $rawPage : null;

        return [
            'search' => $search !== null && trim($search) !== '' ? trim($search) : null,
            'type' => $type,
            'status' => $status,
            'sort' => $sort,
            'direction' => $direction,
            'per_page' => $perPage,
            'page' => $page,
        ];
    }
}
