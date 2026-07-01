<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\CategoryController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\UnitController;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('products', ProductController::class);
    Route::get('products/export', [ProductController::class, 'export'])->name('products.export');

    Route::resource('categories', CategoryController::class)->except(['create', 'edit', 'show']);
    Route::resource('units', UnitController::class)->except(['create', 'edit', 'show']);

    Route::get('stocks', [StockController::class, 'index'])->name('stocks.index');
    Route::post('stocks/receive', [StockController::class, 'receive'])->name('stocks.receive');
    Route::post('stocks/issue', [StockController::class, 'issue'])->name('stocks.issue');
    Route::post('stocks/transfer', [StockController::class, 'transfer'])->name('stocks.transfer');
    Route::post('stocks/adjust', [StockController::class, 'adjust'])->name('stocks.adjust');

    Route::get('stock-movements', [StockController::class, 'movements'])->name('stock-movements.index');

    Route::get('inventory/find', [StockController::class, 'find'])->name('inventory.find');
});
