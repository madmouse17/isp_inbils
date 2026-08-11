<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Reporting\Http\Requests\ReportFilterRequest;
use Modules\Reporting\Queries\AssetUtilizationQuery;
use Modules\Reporting\Queries\AuditLogQuery;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\SlaComplianceQuery;
use Modules\Reporting\Queries\StockCardQuery;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;

class ReportController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/Reports/Index');
    }

    public function business(ReportFilterRequest $request): InertiaResponse
    {
        return Inertia::render('Admin/Reports/Business', [
            'data' => BusinessMetricsQuery::execute($request->dateFrom(), $request->dateTo()),
            'filters' => $request->only(['date_from', 'date_to']),
        ]);
    }

    public function technician(ReportFilterRequest $request): InertiaResponse
    {
        $technicianId = (int) $request->input('technician_id', 0);
        $data = $technicianId ? TechnicianPerformanceQuery::execute($technicianId, $request->dateFrom(), $request->dateTo()) : null;

        return Inertia::render('Admin/Reports/Technician', [
            'data' => $data,
            'technicians' => UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->where('name', 'technician'))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
            'filters' => $request->only(['technician_id', 'date_from', 'date_to']),
        ]);
    }

    public function asset(ReportFilterRequest $request): InertiaResponse
    {
        return Inertia::render('Admin/Reports/Asset', [
            'data' => AssetUtilizationQuery::execute(
                $request->integer('location_id') ?: null,
                $request->input('asset_type'),
                $request->dateFrom(),
                $request->dateTo(),
            ),
            'filters' => $request->only(['location_id', 'asset_type', 'date_from', 'date_to']),
        ]);
    }

    public function sla(ReportFilterRequest $request): InertiaResponse
    {
        return Inertia::render('Admin/Reports/Sla', [
            'data' => SlaComplianceQuery::execute($request->dateFrom(), $request->dateTo(), $request->integer('category_id') ?: null),
            'filters' => $request->only(['date_from', 'date_to', 'category_id']),
        ]);
    }

    public function stockCard(ReportFilterRequest $request): InertiaResponse
    {
        $productId = $request->integer('product_id') ?: 0;
        $data = $productId ? StockCardQuery::execute($productId, $request->integer('location_id') ?: null, $request->dateFrom(), $request->dateTo()) : null;

        return Inertia::render('Admin/Reports/StockCard', [
            'data' => $data,
            'filters' => $request->only(['product_id', 'location_id', 'date_from', 'date_to']),
        ]);
    }

    public function auditLog(ReportFilterRequest $request): InertiaResponse
    {
        return Inertia::render('Admin/Reports/AuditLog', [
            'data' => AuditLogQuery::execute($request->integer('user_id') ?: null, $request->input('log_name'), $request->dateFrom(), $request->dateTo()),
            'filters' => $request->only(['user_id', 'log_name', 'date_from', 'date_to']),
        ]);
    }
}
