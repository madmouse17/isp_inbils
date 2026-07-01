<?php

use Illuminate\Support\Facades\Route;
use Modules\Reporting\Http\Controllers\ReportController;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/business', [ReportController::class, 'business'])->name('reports.business');
    Route::get('reports/technician', [ReportController::class, 'technician'])->name('reports.technician');
    Route::get('reports/asset', [ReportController::class, 'asset'])->name('reports.asset');
    Route::get('reports/sla', [ReportController::class, 'sla'])->name('reports.sla');
    Route::get('reports/stock-card', [ReportController::class, 'stockCard'])->name('reports.stock-card');
    Route::get('reports/audit-log', [ReportController::class, 'auditLog'])->name('reports.audit-log');
});
