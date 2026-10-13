<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Reporting\Http\Controllers\ConsolidatedReportController;
use Modules\Reporting\Http\Controllers\ReportController;
use Modules\Reporting\Http\Controllers\ReportDictionaryController;
use Modules\Reporting\Http\Controllers\ReportDrillController;
use Modules\Reporting\Http\Controllers\ReportExportController;
use Modules\Reporting\Registry\ReportType;

// Each report is gated by its own capability (see Modules\Reporting\Registry\ReportType);
// `report.view` only opens the Reports hub.
Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('can:report.view')
        ->name('reports.index');

    Route::get('reports/business', [ReportController::class, 'business'])
        ->middleware('can:report.business.view')
        ->name('reports.business');

    // Consolidated Business metrics of a Head company over its subsidiaries: read-only. The Head check
    // lives in ConsolidatedReportRequest; the permission is global, so it cannot open a subsidiary's view.
    Route::get('reports/consolidated', [ConsolidatedReportController::class, 'index'])
        ->middleware('can:report.consolidated.view')
        ->name('reports.consolidated');

    Route::get('reports/consolidated/export', [ConsolidatedReportController::class, 'export'])
        ->middleware(['can:report.consolidated.view', 'can:report.export'])
        ->name('reports.consolidated.export');

    Route::get('reports/sales', [ReportController::class, 'sales'])
        ->middleware('can:report.sales.view')
        ->name('reports.sales');

    Route::get('reports/asset', [ReportController::class, 'asset'])
        ->middleware('can:report.asset.view')
        ->name('reports.asset');

    Route::get('reports/sla', [ReportController::class, 'sla'])
        ->middleware('can:report.sla.view')
        ->name('reports.sla');

    Route::get('reports/stock-card', [ReportController::class, 'stockCard'])
        ->middleware('can:report.stock_card.view')
        ->name('reports.stock-card');

    Route::get('reports/dictionary', ReportDictionaryController::class)
        ->middleware('can:report.dictionary.view')
        ->name('reports.dictionary');

    Route::get('reports/technician', [ReportController::class, 'technician'])
        ->middleware('can:report.technician')
        ->name('reports.technician');

    Route::get('reports/audit-log', [ReportController::class, 'auditLog'])
        ->middleware('can:report.audit.view')
        ->name('reports.audit-log');

    // CSV/PDF of each report. `report.export` is required on top of the report's own capability.
    foreach (ReportType::exportable() as $report) {
        Route::get('reports/'.$report->slug().'/export', ReportExportController::class)
            ->defaults('report', $report->value)
            ->middleware(['can:'.$report->permission(), 'can:report.export'])
            ->name(Str::after($report->exportRouteName(), 'admin.'));
    }

    // Source rows behind each aggregate figure. The request adds the source module's list permission.
    foreach (ReportType::drillable() as $report) {
        Route::get('reports/'.$report->slug().'/drill/{metric}', [ReportDrillController::class, 'show'])
            ->where('metric', '[a-z_]+')
            ->defaults('report', $report->value)
            ->middleware('can:'.$report->permission())
            ->name(Str::after($report->drillRouteName(), 'admin.'));

        Route::get('reports/'.$report->slug().'/drill/{metric}/export', [ReportDrillController::class, 'export'])
            ->where('metric', '[a-z_]+')
            ->defaults('report', $report->value)
            ->middleware(['can:'.$report->permission(), 'can:report.export'])
            ->name(Str::after($report->drillRouteName().'.export', 'admin.'));
    }
});
