<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\V1\Technician\InventoryRequestController;
use Modules\Inventory\Http\Controllers\Api\V1\Technician\MaterialReturnController;

Route::middleware('api.technician')->prefix('technician')->name('technician.')->group(function (): void {
    Route::get('material-returns', [MaterialReturnController::class, 'index'])->name('material-returns.index');
    Route::get('material-returns/{materialReturn}', [MaterialReturnController::class, 'show'])->whereNumber('materialReturn')->name('material-returns.show');
    Route::post('material-returns/{materialReturn}/declare', [MaterialReturnController::class, 'declare'])->whereNumber('materialReturn')->name('material-returns.declare');
    Route::get('inventory-requests', [InventoryRequestController::class, 'index'])->name('inventory-requests.index');
});
