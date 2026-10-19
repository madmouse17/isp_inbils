<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MoveOrganizationRequest;
use App\Http\Requests\Admin\StoreOrganizationRequest;
use App\Http\Requests\Admin\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Core\OrganizationUnit;
use App\Services\Core\CompanyService;
use App\Services\Core\LifecycleService;
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

    private const SORTABLE = ['code', 'name', 'type', 'path', 'is_active', 'created_at'];

    /** Stored `type` as the export says it (`lib/statuses/people.ts` ORGANIZATION_TYPE on screen). */
    private const TYPE_LABELS = [
        'company' => 'Perusahaan',
        'branch' => 'Cabang',
        'area' => 'Area',
        'unit' => 'Unit',
        'team' => 'Tim',
    ];

    public function index(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', OrganizationUnit::class);
        $units = $this->filteredQuery($request)
            ->withReferenceCounts()
            ->paginate($this->perPage($request))
            ->withQueryString();
        $canManage = (bool) $request->user()?->can('organization.manage');

        $parentOptions = OrganizationUnit::query()
            ->select(['id', 'public_id', 'code', 'name', 'type', 'path'])
            ->where('is_active', true)
            ->orderBy('path')
            ->orderBy('code')
            ->get();

        return Inertia::render('Admin/Organizations/Index', [
            'organizations' => OrganizationResource::collection($units),
            'parentOptions' => OrganizationResource::collection($parentOptions),
            'filters' => $request->only(['status', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'update' => $canManage,
                'delete' => $canManage,
                'export' => (bool) ($request->user()?->can('organization.export')),
            ],
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        Gate::authorize('store', OrganizationUnit::class);
        $data = $request->validated();
        $data['company_id'] = CompanyService::currentId();
        OrganizationService::create($data);

        return back()->with('success', 'Unit organisasi dibuat.');
    }

    public function update(UpdateOrganizationRequest $request, OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('update', $organization_unit);
        OrganizationService::update($organization_unit, $request->validated());

        return back()->with('success', 'Unit organisasi diperbarui.');
    }

    public function move(MoveOrganizationRequest $request, OrganizationUnit $organization_unit): RedirectResponse
    {
        $parentId = $request->validated('parent_id');
        OrganizationService::move($organization_unit, $parentId === null ? null : (int) $parentId);

        return back()->with('success', 'Unit organisasi dipindahkan.');
    }

    public function destroy(OrganizationUnit $organization_unit): RedirectResponse
    {
        Gate::authorize('delete', $organization_unit);
        LifecycleService::destroyIfUnreferenced($organization_unit);

        return back()->with('success', 'Unit organisasi dihapus permanen.');
    }

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('organization.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('path', 'asc')
            ->fromRequest($request)
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'code' => 'Kode',
            'name' => 'Nama',
            'type' => 'Jenis',
            'path' => 'Jalur',
            'children_count' => 'Sub-unit',
            'is_active' => 'Status',
        ];

        $map = static fn (OrganizationUnit $unit): array => [
            'code' => $unit->code,
            'name' => $unit->name,
            'type' => self::TYPE_LABELS[$unit->type] ?? $unit->type,
            'path' => $unit->path ?? '-',
            'children_count' => $unit->children_count ?? 0,
            'is_active' => $unit->is_active ? 'Aktif' : 'Nonaktif',
        ];

        $filename = 'organizations-export-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Organisasi', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<OrganizationUnit> */
    private function filteredQuery(Request $request): Builder
    {
        $query = OrganizationUnit::query()
            ->withCount('children')
            ->lifecycleStatus($request->input('status'))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('path', 'like', $term);
                });
            });

        return $this->applySort($query, $request, 'code');
    }
}
