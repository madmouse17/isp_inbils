<?php

use Illuminate\Support\Facades\Route;
use Modules\SPK\Http\Controllers\Api\V1\Customer\WorkOrderController as CustomerWorkOrderController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderEvidenceController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderItemController;

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function (): void {
    Route::get('work-orders', [CustomerWorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('work-orders/{wo}', [CustomerWorkOrderController::class, 'show'])->whereNumber('wo')->name('work-orders.show');
});

Route::middleware('api.technician')->prefix('technician/work-orders')->name('technician.work-orders.')->group(function (): void {
    Route::get('{wo}', [WorkOrderController::class, 'show'])->whereNumber('wo')->name('show');
    Route::post('{wo}/start', [WorkOrderController::class, 'start'])->whereNumber('wo')->name('start');
    Route::post('{wo}/en-route', [WorkOrderController::class, 'enRoute'])->whereNumber('wo')->name('en-route');
    Route::post('{wo}/submit', [WorkOrderController::class, 'submit'])->whereNumber('wo')->name('submit');
    Route::post('{wo}/evidence', [WorkOrderEvidenceController::class, 'store'])->whereNumber('wo')->middleware('throttle:api-upload')->name('evidence.store');
    Route::delete('{wo}/evidence/{mediaId}', [WorkOrderEvidenceController::class, 'destroy'])->whereNumber(['wo', 'mediaId'])->name('evidence.destroy');
    Route::post('{wo}/items', [WorkOrderItemController::class, 'store'])->whereNumber('wo')->name('items.store');
    Route::delete('{wo}/items/{item}', [WorkOrderItemController::class, 'destroy'])->whereNumber(['wo', 'item'])->name('items.destroy');
});
