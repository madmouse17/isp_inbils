<?php

use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Platform\CompanySubsidiaryController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\TenantLifecycleController;
use Illuminate\Support\Facades\Route;

// Names carry the platform. prefix of the route shell (routes/platform.php).

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');

    // {company} is a tenant, a top-level company (Company::resolveRouteBinding): the id of a subsidiary is a 404.
    Route::prefix('companies/{company}')->where(['company' => '[0-9]+'])->group(function () {
        Route::get('/', [CompanyController::class, 'show'])->name('companies.show');
        Route::post('subsidiaries', [CompanySubsidiaryController::class, 'store'])->name('companies.subsidiaries.store');
        Route::post('reactivate', [TenantLifecycleController::class, 'reactivate'])->name('companies.reactivate');

        // Suspending or deleting a tenant reaches every one of its users: each needs a fresh platform password confirmation.
        Route::middleware('platform.password.confirm')->group(function () {
            Route::post('suspend', [TenantLifecycleController::class, 'suspend'])->name('companies.suspend');
            Route::post('deletion-request', [TenantLifecycleController::class, 'requestDeletion'])->name('companies.deletion-request.store');
        });
    });
});
