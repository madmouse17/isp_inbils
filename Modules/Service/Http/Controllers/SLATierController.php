<?php

namespace Modules\Service\Http\Controllers;

use App\Http\Controllers\Concerns\AnswersInlineCreate;
use App\Http\Controllers\Concerns\HasIndexQuery;
use App\Http\Controllers\Controller;
use App\Services\Core\CompanyService;
use App\Services\Core\LifecycleService;
use App\Support\ExportQuery;
use App\Support\ServiceHours;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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
    use AnswersInlineCreate;
    use HasIndexQuery;

    private const SORTABLE = ['name', 'response_time_hours', 'resolution_time_hours', 'created_at'];

    private const CALENDAR_LABELS = [SLATier::CALENDAR_24H => '24 jam', SLATier::CALENDAR_BUSINESS => 'Jam layanan'];

    public function index(Request $request, SlaTierCompliance $compliance): InertiaResponse
    {
        Gate::authorize('viewAny', SLATier::class);

        $slaTiers = $this->filteredQuery($request)->withReferenceCounts()->paginate($this->perPage($request))->withQueryString();
        $results = $compliance->forTiers($slaTiers->getCollection()->pluck('id')->all());
        $slaTiers->getCollection()->each(fn (SLATier $tier) => $tier->forceFill($results[$tier->id]));

        return Inertia::render('Admin/Service/SLATiers/Index', [
            'slaTiers' => SLATierResource::collection($slaTiers),
            'filters' => $request->only(['status', 'search', 'sort', 'direction', 'per_page']),
            'can' => [
                'create' => (bool) ($request->user()?->can('service.create') || $request->user()?->can('service.manage')),
                'update' => (bool) ($request->user()?->can('service.update') || $request->user()?->can('service.manage')),
                'delete' => (bool) ($request->user()?->can('service.delete') || $request->user()?->can('service.manage')),
                'export' => (bool) $request->user()?->can('service.export'),
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        Gate::authorize('create', SLATier::class);

        return Inertia::render('Admin/Service/SLATiers/Create', ['serviceHoursSet' => $this->serviceHoursSet()]);
    }

    public function store(StoreSLATierRequest $request): RedirectResponse|JsonResponse
    {
        Gate::authorize('store', SLATier::class);
        $tier = SLATier::query()->create($request->validated());

        if ($this->wantsInlineRow($request)) {
            return response()->json(['data' => ['id' => $tier->public_id, 'label' => $tier->name]], 201);
        }

        return redirect()->route('admin.sla-tiers.index')->with('success', 'Tingkat SLA dibuat.');
    }

    public function edit(SLATier $slaTier): InertiaResponse
    {
        Gate::authorize('edit', $slaTier);

        return Inertia::render('Admin/Service/SLATiers/Edit', ['slaTier' => new SLATierResource($slaTier), 'serviceHoursSet' => $this->serviceHoursSet()]);
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
        LifecycleService::destroyIfUnreferenced($slaTier);

        return back()->with('success', 'Tingkat SLA dihapus permanen.');
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
            'calendar' => 'Hitungan tenggat',
            'is_active' => 'Status',
        ];

        $map = static fn (SLATier $tier): array => [
            'name' => $tier->name,
            'response_time_hours' => $tier->response_time_hours,
            'resolution_time_hours' => $tier->resolution_time_hours,
            'calendar' => self::CALENDAR_LABELS[$tier->calendar] ?? $tier->calendar,
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
            ->lifecycleStatus($request->input('status'))
            ->when(trim((string) $request->input('search')) !== '', function (Builder $query) use ($request): void {
                $term = '%'.trim((string) $request->input('search')).'%';
                $query->where(function (Builder $sub) use ($term): void {
                    $sub->where('name', 'like', $term);
                });
            })
            ->tap(fn (Builder $query) => $this->applySort($query, $request, 'name'));
    }

    /** Whether the company has set its service hours: a tier on the business calendar counts 24 hours until it has. */
    private function serviceHoursSet(): bool
    {
        return ServiceHours::of(CompanyService::current()) !== null;
    }
}
