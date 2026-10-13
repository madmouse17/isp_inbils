<?php

use Illuminate\Support\Facades\Route;
use Modules\NetworkAsset\Http\Controllers\IsolirPageController;
use Modules\NetworkAsset\Http\Controllers\NasDeviceController;
use Modules\NetworkAsset\Http\Controllers\NetworkAccountController;
use Modules\NetworkAsset\Http\Controllers\NetworkAssetController;
use Modules\NetworkAsset\Http\Controllers\OdnController;
use Modules\NetworkAsset\Http\Controllers\OdnDropController;
use Modules\NetworkAsset\Http\Controllers\OdnPonPortController;
use Modules\NetworkAsset\Http\Controllers\OdnPortController;
use Modules\NetworkAsset\Http\Controllers\OdnSplitterController;

Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('network-assets/export', [NetworkAssetController::class, 'export'])->name('network-assets.export');
    Route::get('network-assets/trace', [NetworkAssetController::class, 'trace'])->name('network-assets.trace');
    Route::resource('network-assets', NetworkAssetController::class)->parameters(['network-assets' => 'asset']);
    Route::post('network-assets/{asset}/install', [NetworkAssetController::class, 'install'])->name('network-assets.install');
    Route::post('network-assets/{asset}/remove', [NetworkAssetController::class, 'remove'])->name('network-assets.remove');
    Route::post('network-assets/{asset}/maintenance', [NetworkAssetController::class, 'maintenance'])->name('network-assets.maintenance');
    Route::post('network-assets/{asset}/resume', [NetworkAssetController::class, 'resume'])->name('network-assets.resume');
    Route::post('network-assets/{asset}/damage', [NetworkAssetController::class, 'damage'])->name('network-assets.damage');
    Route::post('network-assets/{asset}/repair', [NetworkAssetController::class, 'repair'])->name('network-assets.repair');
    Route::post('network-assets/{asset}/retire', [NetworkAssetController::class, 'retire'])->name('network-assets.retire');

    // NAS routers (FreeRADIUS clients). RADIUS writes are best effort after the save and retried by network:sync.
    Route::resource('nas-devices', NasDeviceController::class)->parameters(['nas-devices' => 'nasDevice']);

    Route::get('subscriptions/{subscription}/network-account', [NetworkAccountController::class, 'show'])->name('subscriptions.network-account.show');
    Route::post('subscriptions/{subscription}/network-account', [NetworkAccountController::class, 'store'])->name('subscriptions.network-account.store');
    Route::put('subscriptions/{subscription}/network-account', [NetworkAccountController::class, 'update'])->name('subscriptions.network-account.update');
    Route::post('subscriptions/{subscription}/network-account/reveal', [NetworkAccountController::class, 'reveal'])->middleware('throttle:10,1')->name('subscriptions.network-account.reveal');
    Route::post('subscriptions/{subscription}/network-account/resync', [NetworkAccountController::class, 'resync'])->middleware('throttle:6,1')->name('subscriptions.network-account.resync');
});

// Where isolated subscribers are redirected by their router. Public: company contact fields and the login link only.
Route::get('isolir/{company:code}', IsolirPageController::class)->middleware('throttle:isolir')->name('isolir.show');

// Optical distribution network register: PON ports, splitters and customer drops.
Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->prefix('admin/odn')->name('admin.odn.')->group(function () {
    Route::get('/', [OdnController::class, 'index'])->name('index');
    Route::get('assets/{asset}', [OdnController::class, 'show'])->name('assets.show');
    Route::post('assets/{asset}/pon-ports', [OdnPonPortController::class, 'store'])->name('pon-ports.store');
    Route::post('assets/{asset}/splitters', [OdnSplitterController::class, 'store'])->name('splitters.store');
    Route::put('splitters/{splitter}', [OdnSplitterController::class, 'update'])->name('splitters.update');
    Route::delete('splitters/{splitter}', [OdnSplitterController::class, 'destroy'])->name('splitters.destroy');
    Route::delete('ports/{port}', [OdnPortController::class, 'destroy'])->name('ports.destroy');
    Route::post('ports/{port}/drop', [OdnDropController::class, 'store'])->name('drops.store');
    Route::delete('ports/{port}/drop', [OdnDropController::class, 'destroy'])->name('drops.destroy');
});
