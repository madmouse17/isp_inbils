<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\CompanyService;
use App\Support\SearchOptions;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Inventory\Models\Product;
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
use Modules\Service\Models\ServicePackageCategory;

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

    /** @return list<array{id: string, code: string, name: string, is_active: bool}> */
    private function packageCategories(): array
    {
        return ServicePackageCategory::query()->orderBy('sort_order')->orderBy('id')->get(['public_id', 'code', 'name', 'is_active'])
            ->map(fn (ServicePackageCategory $category): array => ['id' => $category->public_id, ...$category->only(['code', 'name', 'is_active'])])
            ->all();
    }

    public function business(ReportFilterRequest $request): InertiaResponse
    {
        $filters = ReportFilters::fromRequest($request);
        $data = $filters->payload(ReportType::Business);

        return Inertia::render('Admin/Reports/Business', [
            'data' => $data,
            'charts' => $filters->charts(ReportType::Business, $data),
            'filters' => $request->only(['date_from', 'date_to', 'service_package_category_id']),
            'packageCategories' => $this->packageCategories(),
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
        $companyId = (int) CompanyService::currentId();
        $acquirerId = $request->record('acquirer_id')['id'] ?? null;

        $report = SalesKpiQuery::execute(
            $companyId,
            $request->dateFrom(),
            $request->dateTo(),
            $acquirerId,
            $sortBy,
            $sortDir,
            $request->record('service_package_category_id')['id'] ?? null,
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
            'charts' => SalesKpiQuery::charts($report, $companyId, $request->dateFrom(), $request->dateTo(), $acquirerId),
            'acquirers' => $report['acquirers'],
            'packageCategories' => $this->packageCategories(),
            'filters' => [
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'acquirer_id' => $request->input('acquirer_id'),
                'service_package_category_id' => $request->input('service_package_category_id'),
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
        $technician = TechnicianReportAccess::target($actor, $request->input('technician_id'));
        $filters = ReportFilters::fromRequest($request, $technician);
        $data = $technician ? $filters->payload(ReportType::Technician) : null;
        $eligible = TechnicianPerformanceQuery::eligibleTechnicians($actor);

        return Inertia::render('Admin/Reports/Technician', [
            'data' => $data,
            'charts' => $data === null ? null : $filters->charts(ReportType::Technician, $data),
            'technicianOptions' => SearchOptions::for(
                $request,
                'technician',
                clone $eligible,
                ['users.name', 'users.email'],
                $this->presentUser(...),
                $technician?->public_id,
            ),
            'selectedTechnician' => $this->selectedOption($eligible, $technician?->public_id, $this->presentUser(...)),
            'hasTechnicians' => (clone $eligible)->exists(),
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
        $data = $filters->payload(ReportType::Sla);

        return Inertia::render('Admin/Reports/Sla', [
            'data' => $data,
            'charts' => $filters->charts(ReportType::Sla, $data),
            'filters' => $request->only(['date_from', 'date_to', 'category_id']),
            'meta' => MetricRegistry::meta(ReportType::Sla),
            ...$this->exportAndDrill($request, ReportType::Sla, $filters),
        ]);
    }

    public function stockCard(ReportFilterRequest $request): InertiaResponse
    {
        $actor = $request->user();
        $product = $request->record('product_id');
        $location = $request->record('location_id');
        $movements = null;
        // The ledger keeps the rows of a deleted product, so the picker must still find it.
        $products = Product::query()->withTrashed();
        $locations = Location::query();

        if ($product !== null) {
            $paginator = StockCardQuery::execute(
                (int) CompanyService::currentId(),
                $product['id'],
                $location['id'] ?? null,
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
            'productOptions' => SearchOptions::for($request, 'product', clone $products, ['name', 'sku'], $this->presentProduct(...), $product['public_id'] ?? null),
            'selectedProduct' => $this->selectedOption($products, $product['public_id'] ?? null, $this->presentProduct(...)),
            'locationOptions' => SearchOptions::for($request, 'location', clone $locations, ['name', 'code'], $this->presentLocation(...), $location['public_id'] ?? null),
            'selectedLocation' => $this->selectedOption($locations, $location['public_id'] ?? null, $this->presentLocation(...)),
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
            'links' => $product !== null ? [
                'stocks' => $this->inventoryLink($request, 'admin.stocks.index', $product['public_id'], $location['public_id'] ?? null),
                'movements' => $this->inventoryLink($request, 'admin.stock-movements.index', $product['public_id'], $location['public_id'] ?? null),
            ] : null,
        ]);
    }

    public function auditLog(ReportFilterRequest $request): InertiaResponse
    {
        Gate::authorize('report.audit.view');

        $sortBy = $request->input('sort_by') ?? $request->input('sort') ?? 'created_at';
        $sortDir = $request->input('sort_dir') ?? $request->input('direction') ?? 'desc';
        $perPage = $request->integer('per_page') ?: 25;
        $user = $request->record('user_id');
        // User is not company scoped: members come from company_user.
        $members = User::query()->whereIn(
            'users.id',
            DB::table('company_user')->where('company_id', CompanyService::currentId())->select('user_id'),
        );

        $paginator = AuditLogQuery::paginate(
            userId: $user['id'] ?? null,
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
            'userOptions' => SearchOptions::for($request, 'user', clone $members, ['users.name', 'users.email'], $this->presentUser(...), $user['public_id'] ?? null),
            'selectedUser' => $this->selectedOption($members, $user['public_id'] ?? null, $this->presentUser(...)),
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
     * The option of the current filter value, read from the same company-scoped query as the search:
     * a foreign id finds nothing, so its name is never sent.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $base
     * @param  Closure(Model): array{value: string, label: string, description: ?string}  $present
     * @return array{value: string, label: string, description: ?string}|null
     */
    private function selectedOption(Builder $base, ?string $publicId, Closure $present): ?array
    {
        $model = $publicId === null ? null : (clone $base)->wherePublicId($publicId)->first();

        return $model === null ? null : $present($model);
    }

    /** @return array{value: string, label: string, description: ?string} */
    private function presentUser(User $user): array
    {
        return ['value' => $user->public_id, 'label' => $user->name, 'description' => $user->email];
    }

    /** @return array{value: string, label: string, description: ?string} */
    private function presentProduct(Product $product): array
    {
        return ['value' => $product->public_id, 'label' => $product->name, 'description' => $product->sku];
    }

    /** @return array{value: string, label: string, description: ?string} */
    private function presentLocation(Location $location): array
    {
        return ['value' => $location->public_id, 'label' => $location->name, 'description' => $location->code];
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

    private function inventoryLink(ReportFilterRequest $request, string $route, string $productPublicId, ?string $locationPublicId): ?string
    {
        return $request->user()->can('inventory.view')
            ? route($route, array_filter(['product_id' => $productPublicId, 'location_id' => $locationPublicId]), false)
            : null;
    }
}
