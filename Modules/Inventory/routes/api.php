<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\Api\V1\Technician\InventoryRequestController;
use Modules\Inventory\Http\Controllers\Api\V1\Technician\MaterialReturnController;
use Modules\Inventory\Http\Controllers\Api\V1\Technician\ProductController;
use Modules\Inventory\Http\Controllers\Api\V1\Technician\WarehouseController;

Route::middleware('api.technician')->prefix('technician')->name('technician.')->group(function (): void {
    Route::get('material-returns', [MaterialReturnController::class, 'index'])->name('material-returns.index');
    Route::get('material-returns/{materialReturn}', [MaterialReturnController::class, 'show'])->whereUlid('materialReturn')->name('material-returns.show');
    Route::post('material-returns/{materialReturn}/declare', [MaterialReturnController::class, 'declare'])->whereUlid('materialReturn')->name('material-returns.declare');
    Route::get('inventory-requests', [InventoryRequestController::class, 'index'])->name('inventory-requests.index');
    Route::post('inventory-requests', [InventoryRequestController::class, 'store'])->name('inventory-requests.store');
    Route::post('inventory-requests/{inventoryRequest}/cancel', [InventoryRequestController::class, 'cancel'])->whereUlid('inventoryRequest')->name('inventory-requests.cancel');
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
});
