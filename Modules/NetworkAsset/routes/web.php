<?php

use Illuminate\Support\Facades\Route;
use Modules\NetworkAsset\Http\Controllers\NetworkAssetController;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('network-assets', NetworkAssetController::class);
    Route::post('network-assets/{asset}/install', [NetworkAssetController::class, 'install'])->name('network-assets.install');
    Route::post('network-assets/{asset}/remove', [NetworkAssetController::class, 'remove'])->name('network-assets.remove');
    Route::post('network-assets/{asset}/maintenance', [NetworkAssetController::class, 'maintenance'])->name('network-assets.maintenance');
    Route::post('network-assets/{asset}/resume', [NetworkAssetController::class, 'resume'])->name('network-assets.resume');
    Route::post('network-assets/{asset}/damage', [NetworkAssetController::class, 'damage'])->name('network-assets.damage');
    Route::post('network-assets/{asset}/repair', [NetworkAssetController::class, 'repair'])->name('network-assets.repair');
    Route::post('network-assets/{asset}/retire', [NetworkAssetController::class, 'retire'])->name('network-assets.retire');
    Route::get('network-assets/trace', [NetworkAssetController::class, 'trace'])->name('network-assets.trace');
});
