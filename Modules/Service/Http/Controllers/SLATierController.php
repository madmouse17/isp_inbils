<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Support\ExportQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Service\Http\Requests\StoreSLATierRequest;
use Modules\Service\Http\Requests\UpdateSLATierRequest;
use Modules\Service\Http\Resources\SLATierResource;
use Modules\Service\Models\SLATier;
use Modules\Service\Services\SlaTierCompliance;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SLATierController extends Controller
{
    use HasIndexQuery;

    private const SORTABLE = ['name', 'response_time_hours', 'resolution_time_hours', 'created_at'];

    public function index(Request $request, SlaTierCompliance $compliance): InertiaResponse
    {
        Gate::authorize('viewAny', SLATier::class);

        $slaTiers = $this->filteredQuery($request)->paginate($this->perPage($request))->withQueryString();
        $results = $compliance->forTiers($slaTiers->getCollection()->pluck('id')->all());
        $slaTiers->getCollection()->each(fn (SLATier $tier) => $tier->forceFill($results[$tier->id]));

        return Inertia::render('Admin/Service/SLATiers/Index', [
            'slaTiers' => SLATierResource::collection($slaTiers),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
                'export' => (bool) $request->user()?->can('service.export'),
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', SLATier::class);

        return Inertia::render('Admin/Service/SLATiers/Create');
    }

    public function store(StoreSLATierRequest $request): RedirectResponse
    {
        Gate::authorize('store', SLATier::class);
        SLATier::query()->create($request->validated());

        return redirect()->route('admin.sla-tiers.index')->with('success', 'Tingkat SLA dibuat.');
    }

    public function edit(SLATier $slaTier): InertiaResponse
    {
        Gate::authorize('edit', $slaTier);

        return Inertia::render('Admin/Service/SLATiers/Edit', ['slaTier' => new SLATierResource($slaTier)]);
    }

    public function update(UpdateSLATierRequest $request, SLATier $slaTier): RedirectResponse
    {
        Gate::authorize('update', $slaTier);
        $slaTier->update($request->validated());

        return redirect()->route('admin.sla-tiers.index')->with('success', 'Tingkat SLA diperbarui.');
    }

    public function destroy(SLATier $slaTier): RedirectResponse
    {
        Gate::authorize('delete', $slaTier);
        $slaTier->delete();

        return back()->with('success', 'Tingkat SLA dihapus.');
    }

    public function export(Request $request): HttpResponse|StreamedResponse
    {
        Gate::authorize('service.export');

        $export = ExportQuery::make($this->filteredQuery($request))
            ->defaultSort('name', 'asc')
            ->fromRequest($request)
            ->maxRows((int) config('exports.max_rows', 5000));

        $columns = [
            'name' => 'Nama',
            'response_time_hours' => 'Target respons (jam)',
            'resolution_time_hours' => 'Target penyelesaian (jam)',
            'is_active' => 'Status',
        ];

        $map = static fn (SLATier $tier): array => [
            'name' => $tier->name,
            'response_time_hours' => $tier->response_time_hours,
            'resolution_time_hours' => $tier->resolution_time_hours,
            'is_active' => $tier->is_active ? 'Aktif' : 'Nonaktif',
        ];

        $filename = 'sla-tiers-export-'.now()->format('Ymd-His');

        return strtolower((string) $request->input('format', 'csv')) === 'pdf'
            ? $export->streamPdf('Tingkat SLA', $columns, $map, $filename.'.pdf')
            : $export->streamCsv($columns, $map, $filename.'.csv');
    }

    /** @return Builder<SLATier> */
    private function filteredQuery(Request $request): Builder
    {
        return SLATier::query()
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term);
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'name'));
    }
}
