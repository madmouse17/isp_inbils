<?php

use Illuminate\Support\Facades\Route;
use Modules\Service\Http\Controllers\Api\V1\Customer\AnnouncementController;
use Modules\Service\Http\Controllers\Api\V1\Customer\HomeFeedController;
use Modules\Service\Http\Controllers\Api\V1\Customer\PromoBannerTapController;

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function (): void {
    Route::get('home-feed', HomeFeedController::class)->name('home-feed');
    Route::get('announcements/{announcement}', [AnnouncementController::class, 'show'])->whereUlid('announcement')->name('announcements.show');
    Route::post('promo-banners/{banner}/tap', PromoBannerTapController::class)->whereUlid('banner')->middleware('throttle:api-promo-tap')->name('promo-banners.tap');
});
