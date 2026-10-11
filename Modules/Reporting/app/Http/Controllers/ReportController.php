<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Reporting\Drill\DrillRegistry;
use Modules\Reporting\Http\Requests\ReportFilterRequest;
use Modules\Reporting\Http\Resources\AuditLogResource;
use Modules\Reporting\Http\Resources\SalesKpiRowResource;
use Modules\Reporting\Http\Resources\StockMovementResource;
use Modules\Reporting\Queries\AuditLogQuery;
use Modules\Reporting\Queries\ReportFilters;
use Modules\Reporting\Queries\SalesKpiQuery;
use Modules\Reporting\Queries\StockCardQuery;
use Modules\Reporting\Queries\StockMovementReferences;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;
use Modules\Reporting\Registry\MetricRegistry;
use Modules\Reporting\Registry\ReportType;
use Modules\Reporting\Services\TechnicianReportAccess;

class ReportController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Admin/Reports/Index', [
            'reports' => array_map(
                fn (ReportType $report): array => $report->toCard(),
                ReportType::openableBy($request->user()),
            ),
        ]);
    }

    public function business(ReportFilterRequest $request): InertiaResponse
    {
        $filters = ReportFilters::fromRequest($request);

        return Inertia::render('Admin/Reports/Business', [
            'data' => $filters->payload(ReportType::Business),
            'filters' => $request->only(['date_from', 'date_to']),
            'meta' => MetricRegistry::meta(ReportType::Business),
            ...$this->exportAndDrill($request, ReportType::Business, $filters),
        ]);
    }

    public function sales(ReportFilterRequest $request): InertiaResponse
    {
        abort_unless(CompanyService::currentId() !== null, 403);

        $sortBy = (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'name');
        $sortDir = strtolower((string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'asc'));
        $perPage = in_array($request->integer('per_page', 10), [10, 25, 50], true) ? $request->integer('per_page', 10) : 10;
        $page = max(1, $request->integer('page', 1));

        $report = SalesKpiQuery::execute(
            (int) CompanyService::currentId(),
            $request->dateFrom(),
            $request->dateTo(),
            $request->filled('acquirer_id') ? $request->integer('acquirer_id') : null,
            $sortBy,
            $sortDir,
        );

        return Inertia::render('Admin/Reports/Sales', [
            'data' => [
                'rows' => SalesKpiRowResource::collection(new LengthAwarePaginator(
                    array_slice($report['rows'], ($page - 1) * $perPage, $perPage),
                    count($report['rows']),
                    $perPage,
                    $page,
                    ['path' => $request->url(), 'query' => $request->query()],
                )),
                'unassigned' => $report['unassigned'],
                'totals' => $report['totals'],
            ],
            'acquirers' => $report['acquirers'],
            'filters' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'acquirer_id' => $request->input('acquirer_id'),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
                'per_page' => $perPage,
            ],
            'meta' => MetricRegistry::meta(ReportType::Sales),
        ]);
    }

    public function technician(ReportFilterRequest $request): InertiaResponse
    {
        $actor = $request->user();
        TechnicianReportAccess::companyId($actor);
        $technicianId = TechnicianReportAccess::target($actor, $request->input('technician_id'));
        $filters = ReportFilters::fromRequest($request, $technicianId);

        return Inertia::render('Admin/Reports/Technician', [
            'data' => $technicianId ? $filters->payload(ReportType::Technician) : null,
            'technicians' => UserResource::collection(
                TechnicianPerformanceQuery::eligibleTechnicians($actor)->orderBy('name')->get()
            ),
            'filters' => $request->only(['technician_id', 'date_from', 'date_to']),
            'meta' => MetricRegistry::meta(ReportType::Technician),
            ...$this->exportAndDrill($request, ReportType::Technician, $filters),
        ]);
    }

    public function asset(ReportFilterRequest $request): InertiaResponse
    {
        $filters = ReportFilters::fromRequest($request);

        return Inertia::render('Admin/Reports/Asset', [
            'data' => $filters->payload(ReportType::Asset),
            'filters' => $request->only(['location_id', 'asset_type', 'date_from', 'date_to']),
            'meta' => MetricRegistry::meta(ReportType::Asset),
            ...$this->exportAndDrill($request, ReportType::Asset, $filters),
        ]);
    }

    public function sla(ReportFilterRequest $request): InertiaResponse
    {
        $filters = ReportFilters::fromRequest($request);

        return Inertia::render('Admin/Reports/Sla', [
            'data' => $filters->payload(ReportType::Sla),
            'filters' => $request->only(['date_from', 'date_to', 'category_id']),
            'meta' => MetricRegistry::meta(ReportType::Sla),
            ...$this->exportAndDrill($request, ReportType::Sla, $filters),
        ]);
    }

    public function stockCard(ReportFilterRequest $request): InertiaResponse
    {
        $actor = $request->user();
        $productId = $request->integer('product_id') ?: null;
        $locationId = $request->integer('location_id') ?: null;
        $movements = null;

        if ($productId) {
            $paginator = StockCardQuery::execute(
                (int) CompanyService::currentId(),
                $productId,
                $locationId,
                $request->dateFrom(),
                $request->dateTo(),
                (string) ($request->input('sort_by') ?? $request->input('sort') ?? 'created_at'),
                (string) ($request->input('sort_dir') ?? $request->input('direction') ?? 'desc'),
                in_array($request->integer('per_page', 10), [10, 25, 50], true) ? $request->integer('per_page', 10) : 10,
            )['movements'];

            $references = StockMovementReferences::resolve($paginator->getCollection(), $actor);
            $paginator->getCollection()->each(
                fn ($movement) => $movement->setAttribute('reference_link', $references[$movement->id] ?? null)
            );
            $movements = StockMovementResource::collection($paginator);
        }

        return Inertia::render('Admin/Reports/StockCard', [
            'movements' => $movements,
            'filters' => [
                'product_id' => $request->input('product_id'),
                'location_id' => $request->input('location_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'sort_by' => $request->input('sort_by', $request->input('sort', 'created_at')),
                'sort_dir' => $request->input('sort_dir', $request->input('direction', 'desc')),
                'per_page' => $request->integer('per_page', 10),
            ],
            'meta' => MetricRegistry::meta(ReportType::StockCard),
            'can' => ['export' => $actor->can('report.export')],
            'links' => $productId ? [
                'stocks' => $this->inventoryLink($request, 'admin.stocks.index', $productId, $locationId),
                'movements' => $this->inventoryLink($request, 'admin.stock-movements.index', $productId, $locationId),
            ] : null,
        ]);
    }

    public function auditLog(ReportFilterRequest $request): InertiaResponse
    {
        Gate::authorize('report.audit.view');

        $sortBy = $request->input('sort_by') ?? $request->input('sort') ?? 'created_at';
        $sortDir = $request->input('sort_dir') ?? $request->input('direction') ?? 'desc';
        $perPage = $request->integer('per_page') ?: 25;

        $paginator = AuditLogQuery::paginate(
            userId: $request->integer('user_id') ?: null,
            logName: $request->input('log_name'),
            dateFrom: $request->dateFrom(),
            dateTo: $request->dateTo(),
            search: $request->input('search'),
            event: $request->input('event'),
            perPage: $perPage,
            sortField: $sortBy,
            sortDirection: $sortDir
        );

        return Inertia::render('Admin/Reports/AuditLog', [
            'data' => AuditLogResource::collection($paginator),
            'filters' => [
                'user_id' => $request->input('user_id'),
                'log_name' => $request->input('log_name'),
                'event' => $request->input('event'),
                'search' => $request->input('search'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'sort_by' => $sortBy,
                'sort_dir' => $sortDir,
                'per_page' => $perPage,
                'page' => $request->integer('page') ?: 1,
            ],
            'can' => [
                'export' => auth()->user()?->can('report.export') ?? false,
                'view_properties' => auth()->user()?->can('report.audit.view') ?? false,
            ],
        ]);
    }

    /**
     * The export control and the drill-down links of an aggregate report page. The report's own
     * capability already opened the page; `report.export` and each source module's list permission
     * decide what is offered on top. The server re-checks both on every request.
     *
     * @return array{can: array{export: bool}, drill: array<string, string>}
     */
    private function exportAndDrill(ReportFilterRequest $request, ReportType $report, ReportFilters $filters): array
    {
        $actor = $request->user();

        return [
            'can' => ['export' => $actor->can('report.export')],
            'drill' => $report === ReportType::Technician && $filters->technicianId === null
                ? []
                : DrillRegistry::links($report, $actor, $filters->query($report)),
        ];
    }

    private function inventoryLink(ReportFilterRequest $request, string $route, int $productId, ?int $locationId): ?string
    {
        return $request->user()->can('inventory.view')
            ? route($route, array_filter(['product_id' => $productId, 'location_id' => $locationId]), false)
            : null;
    }
}
