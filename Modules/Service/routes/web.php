<?php

use Illuminate\Support\Facades\Route;
use Modules\Service\Http\Controllers\BandwidthProfileController;
use Modules\Service\Http\Controllers\ServicePackageController;
use Modules\Service\Http\Controllers\SLATierController;
use Modules\Service\Http\Controllers\SpeedProfileController;

Route::middleware(['auth', 'verified', 'require.has.company'])->group(function () {
    Route::group(['prefix' => 'admin', 'as' => 'admin.'], function () {
        Route::resource('service-packages', ServicePackageController::class);
        Route::post('service-packages/{pkg}/deactivate', [ServicePackageController::class, 'deactivate'])->name('service-packages.deactivate');
        Route::resource('bandwidth-profiles', BandwidthProfileController::class)->except(['show']);
        Route::resource('speed-profiles', SpeedProfileController::class)->except(['show']);
        Route::resource('sla-tiers', SLATierController::class)->except(['show']);
    });
});
