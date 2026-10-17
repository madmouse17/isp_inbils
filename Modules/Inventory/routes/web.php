<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\CategoryController;
use Modules\Inventory\Http\Controllers\InventoryRequestController;
use Modules\Inventory\Http\Controllers\MaterialReturnController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\UnitController;

Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->prefix('admin')->name('admin.')->whereUlid([
    'inventory_request', 'material_return', 'product', 'category', 'unit', 'adjustment',
])->group(function () {
    // Inventory Requests
    Route::get('inventory/requests', [InventoryRequestController::class, 'index'])->name('inventory.requests.index');
    Route::get('inventory/requests/create', [InventoryRequestController::class, 'create'])->name('inventory.requests.create');
    Route::post('inventory/requests', [InventoryRequestController::class, 'store'])->name('inventory.requests.store');
    Route::get('inventory/requests/{inventory_request}', [InventoryRequestController::class, 'show'])->name('inventory.requests.show');
    Route::post('inventory/requests/{inventory_request}/submit', [InventoryRequestController::class, 'submit'])->name('inventory.requests.submit');
    Route::post('inventory/requests/{inventory_request}/approve', [InventoryRequestController::class, 'approve'])->name('inventory.requests.approve');
    Route::post('inventory/requests/{inventory_request}/prepare', [InventoryRequestController::class, 'prepare'])->name('inventory.requests.prepare');
    Route::post('inventory/requests/{inventory_request}/ready', [InventoryRequestController::class, 'markReady'])->name('inventory.requests.ready');
    Route::post('inventory/requests/{inventory_request}/issue', [InventoryRequestController::class, 'issue'])->name('inventory.requests.issue');
    Route::post('inventory/requests/{inventory_request}/reject', [InventoryRequestController::class, 'reject'])->name('inventory.requests.reject');
    Route::post('inventory/requests/{inventory_request}/cancel', [InventoryRequestController::class, 'cancel'])->name('inventory.requests.cancel');

    // Material Returns (Custody & Physical Return)
    Route::get('inventory/returns', [MaterialReturnController::class, 'index'])->name('inventory.returns.index');
    Route::get('inventory/returns/{material_return}', [MaterialReturnController::class, 'show'])->name('inventory.returns.show');
    Route::post('inventory/returns/{material_return}/declare', [MaterialReturnController::class, 'declareUsage'])->name('inventory.returns.declare');
    Route::post('inventory/returns/{material_return}/receive-accept', [MaterialReturnController::class, 'receiveAccept'])->name('inventory.returns.receive-accept');
    Route::post('inventory/returns/{material_return}/receive-reject', [MaterialReturnController::class, 'receiveReject'])->name('inventory.returns.receive-reject');
    Route::post('inventory/returns/{material_return}/resolve', [MaterialReturnController::class, 'resolveException'])->name('inventory.returns.resolve');

    Route::get('stocks/export', [StockController::class, 'export'])->name('stocks.export');
    Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
    Route::resource('products', ProductController::class);

    Route::get('categories/export', [CategoryController::class, 'export'])->name('categories.export');
    Route::resource('categories', CategoryController::class)->except(['create', 'edit', 'show']);
    Route::get('units/export', [UnitController::class, 'export'])->name('units.export');
    Route::resource('units', UnitController::class)->except(['create', 'edit', 'show']);

    Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    Route::post('stocks/receive', [StockController::class, 'receive'])->name('stocks.receive');
    Route::post('stocks/issue', [StockController::class, 'issue'])->name('stocks.issue');
    Route::post('stocks/transfer', [StockController::class, 'transfer'])->name('stocks.transfer');
    Route::post('stocks/adjust', [StockController::class, 'adjust'])->name('stocks.adjust');
    Route::post('stocks/adjust/direct', [StockController::class, 'adjustDirect'])->name('stocks.adjust.direct');
    Route::post('stocks/adjustments/{adjustment}/approve', [StockController::class, 'approveAdjustment'])->name('stocks.adjustments.approve');
    Route::post('stocks/adjustments/{adjustment}/reject', [StockController::class, 'rejectAdjustment'])->name('stocks.adjustments.reject');

    Route::get('stock-movements/export', [StockController::class, 'movementsExport'])->name('stock-movements.export');
    Route::get('stock-movements', [StockController::class, 'movements'])->name('stock-movements.index');

    Route::get('inventory/find', [StockController::class, 'find'])->name('inventory.find');
});
