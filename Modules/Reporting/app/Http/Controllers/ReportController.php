<?php

namespace Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Reporting\Queries\AuditLogQuery;
use Modules\Reporting\Queries\AssetUtilizationQuery;
use Modules\Reporting\Queries\BusinessMetricsQuery;
use Modules\Reporting\Queries\SlaComplianceQuery;
use Modules\Reporting\Queries\StockCardQuery;
use Modules\Reporting\Queries\TechnicianPerformanceQuery;

class ReportController extends Controller
{
    public function index(): InertiaResponse
    {
        Gate::authorize('report.view');
        return Inertia::render('Admin/Reports/Index');
    }

    public function business(Request $request): InertiaResponse
    {
        Gate::authorize('report.business');
        return Inertia::render('Admin/Reports/Business', [
            'data' => BusinessMetricsQuery::execute($request->input('date_from'), $request->input('date_to')),
            'filters' => $request->only(['date_from', 'date_to']),
        ]);
    }

    public function technician(Request $request): InertiaResponse
    {
        Gate::authorize('report.technician');
        $technicianId = (int) $request->input('technician_id', 0);
        $data = $technicianId ? TechnicianPerformanceQuery::execute($technicianId, $request->input('date_from'), $request->input('date_to')) : null;

        return Inertia::render('Admin/Reports/Technician', [
            'data' => $data,
            'technicians' => \App\Http\Resources\UserResource::collection(
                User::query()->whereHas('roles', fn ($q) => $q->where('name', 'technician'))
                    ->where('is_active', true)->orderBy('name')->get()
            ),
            'filters' => $request->only(['technician_id', 'date_from', 'date_to']),
        ]);
    }

    public function asset(Request $request): InertiaResponse
    {
        Gate::authorize('report.asset');
        return Inertia::render('Admin/Reports/Asset', [
            'data' => AssetUtilizationQuery::execute(
                $request->integer('location_id') ?: null,
                $request->input('asset_type'),
                $request->input('date_from'),
                $request->input('date_to'),
            ),
            'filters' => $request->only(['location_id', 'asset_type', 'date_from', 'date_to']),
        ]);
    }

    public function sla(Request $request): InertiaResponse
    {
        Gate::authorize('report.sla');
        return Inertia::render('Admin/Reports/Sla', [
            'data' => SlaComplianceQuery::execute($request->input('date_from'), $request->input('date_to'), $request->integer('category_id') ?: null),
            'filters' => $request->only(['date_from', 'date_to', 'category_id']),
        ]);
    }

    public function stockCard(Request $request): InertiaResponse
    {
        Gate::authorize('report.stock_card');
        $productId = $request->integer('product_id') ?: 0;
        $data = $productId ? StockCardQuery::execute($productId, $request->integer('location_id') ?: null, $request->input('date_from'), $request->input('date_to')) : null;

        return Inertia::render('Admin/Reports/StockCard', [
            'data' => $data,
            'filters' => $request->only(['product_id', 'location_id', 'date_from', 'date_to']),
        ]);
    }

    public function auditLog(Request $request): InertiaResponse
    {
        Gate::authorize('report.audit_log');
        return Inertia::render('Admin/Reports/AuditLog', [
            'data' => AuditLogQuery::execute($request->integer('user_id') ?: null, $request->input('log_name'), $request->input('date_from'), $request->input('date_to')),
            'filters' => $request->only(['user_id', 'log_name', 'date_from', 'date_to']),
        ]);
    }
}
