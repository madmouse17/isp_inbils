<?php

use Illuminate\Support\Facades\Route;
use Modules\Service\Http\Controllers\AnnouncementController;
use Modules\Service\Http\Controllers\BandwidthProfileController;
use Modules\Service\Http\Controllers\PromoBannerController;
use Modules\Service\Http\Controllers\ServicePackageCategoryController;
use Modules\Service\Http\Controllers\ServicePackageController;
use Modules\Service\Http\Controllers\SLATierController;
use Modules\Service\Http\Controllers\SpeedProfileController;

Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->group(function () {
    Route::group(['prefix' => 'admin', 'as' => 'admin.'], function () {
        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::post('announcements/{announcement}/publish', [AnnouncementController::class, 'publish'])->name('announcements.publish');
        Route::post('announcements/{announcement}/deactivate', [AnnouncementController::class, 'deactivate'])->name('announcements.deactivate');
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
        Route::post('promo-banners', [PromoBannerController::class, 'store'])->name('promo-banners.store');
        Route::put('promo-banners/{promo_banner}', [PromoBannerController::class, 'update'])->name('promo-banners.update');
        Route::post('promo-banners/{promo_banner}/publish', [PromoBannerController::class, 'publish'])->name('promo-banners.publish');
        Route::post('promo-banners/{promo_banner}/deactivate', [PromoBannerController::class, 'deactivate'])->name('promo-banners.deactivate');
        Route::delete('promo-banners/{promo_banner}', [PromoBannerController::class, 'destroy'])->name('promo-banners.destroy');
        Route::get('service-packages/export', [ServicePackageController::class, 'export'])->name('service-packages.export');
        Route::resource('service-packages', ServicePackageController::class);
        Route::post('service-packages/{pkg}/deactivate', [ServicePackageController::class, 'deactivate'])->name('service-packages.deactivate');
        Route::resource('service-package-categories', ServicePackageCategoryController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::get('bandwidth-profiles/export', [BandwidthProfileController::class, 'export'])->name('bandwidth-profiles.export');
        Route::resource('bandwidth-profiles', BandwidthProfileController::class)->except(['show']);
        Route::get('speed-profiles/export', [SpeedProfileController::class, 'export'])->name('speed-profiles.export');
        Route::resource('speed-profiles', SpeedProfileController::class)->except(['show']);
        Route::get('sla-tiers/export', [SLATierController::class, 'export'])->name('sla-tiers.export');
        Route::resource('sla-tiers', SLATierController::class)->except(['show']);
    });
});
