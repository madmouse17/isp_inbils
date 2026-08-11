<?php

use Illuminate\Support\Facades\Route;
use Modules\Service\Http\Controllers\BandwidthProfileController;
use Modules\Service\Http\Controllers\ServicePackageController;
use Modules\Service\Http\Controllers\SLATierController;
use Modules\Service\Http\Controllers\SpeedProfileController;

Route::middleware(['auth', 'verified', 'require.has.company'])->group(function () {
    Route::group(['prefix' => 'admin', 'as' => 'admin.'], function () {
        Route::get('service-packages/export', [ServicePackageController::class, 'export'])->name('service-packages.export');
        Route::resource('service-packages', ServicePackageController::class);
        Route::post('service-packages/{pkg}/deactivate', [ServicePackageController::class, 'deactivate'])->name('service-packages.deactivate');
        Route::get('bandwidth-profiles/export', [BandwidthProfileController::class, 'export'])->name('bandwidth-profiles.export');
        Route::resource('bandwidth-profiles', BandwidthProfileController::class)->except(['show']);
        Route::get('speed-profiles/export', [SpeedProfileController::class, 'export'])->name('speed-profiles.export');
        Route::resource('speed-profiles', SpeedProfileController::class)->except(['show']);
        Route::get('sla-tiers/export', [SLATierController::class, 'export'])->name('sla-tiers.export');
        Route::resource('sla-tiers', SLATierController::class)->except(['show']);
    });
});
