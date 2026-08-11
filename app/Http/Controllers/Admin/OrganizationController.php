<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Models\Core\OrganizationUnit;
use App\Services\Core\CompanyService;
use App\Services\Core\OrganizationService;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrganizationController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['code', 'name', 'type', 'created_at'];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', OrganizationUnit::class);
        $units = $this->filteredQuery($request)
            ->paginate(10)
            ->withQueryString();

        $parentOptions = OrganizationUnit::query()
            ->select(['id', 'code', 'name', 'type', 'path'])
            ->where('is_active', true)
            ->orderBy('path')
            ->orderBy('code')
            ->get();

        return Inertia::render('Admin/Organizations/Index', [
            'organizations' => OrganizationResource::collection($units),
            'parentOptions' => OrganizationResource::collection($parentOptions),
            'filters' => $request->only(['search', 'sort', 'direction']),
            'can' => [
                'export' => (bool) ($request->user()?->can('organization.export')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('store', OrganizationUnit::class);
        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:organization_units,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ]);
        $data['company_id'] = CompanyService::currentId();
        OrganizationService::create($data);

        return back()->with('success', 'Organization unit created.');
    }

    public function update(Request $request, OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('update', $organization_unit);
        OrganizationService::update($organization_unit, $request->validate([
            'parent_id' => ['nullable', 'exists:organization_units,id'],
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_active' => ['boolean'],
        ]));

        return back()->with('success', 'Organization unit updated.');
    }

    public function move(Request $request, OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('update', $organization_unit);
        $request->validate(['parent_id' => ['nullable', 'exists:organization_units,id']]);
        OrganizationService::move($organization_unit, $request->integer('parent_id') ?: 0);

        return back()->with('success', 'Organization unit moved.');
    }

    public function destroy(OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('delete', $organization_unit);
        OrganizationService::delete($organization_unit);

        return back()->with('success', 'Organization unit deleted.');
    }

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('organization.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('path', 'asc')
            ->fromRequest($request)
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'code' => 'Code',
            'name' => 'Name',
            'type' => 'Type',
            'path' => 'Path',
            'children_count' => 'Children',
            'is_active' => 'Status',
        ];

        $map = static fn (OrganizationUnit $unit): array => [
            'code' => $unit->code,
            'name' => $unit->name,
            'type' => $unit->type,
            'path' => $unit->path ?? '-',
            'children_count' => $unit->children_count ?? 0,
            'is_active' => $unit->is_active ? 'Active' : 'Inactive',
        ];

        $filename = 'organizations-export-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Organizations', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<OrganizationUnit> */
    private function filteredQuery(Request $request): Builder
    {
        $query = OrganizationUnit::query()
            ->withCount('children')
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('path', 'like', $term);
                });
            });

        return $this->applySort($query, $request, 'code');
    }
}
