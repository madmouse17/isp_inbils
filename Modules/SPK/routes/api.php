<?php

use Illuminate\Support\Facades\Route;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderEvidenceController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderItemController;

Route::middleware('api.technician')->prefix('technician/work-orders')->name('technician.work-orders.')->group(function (): void {
    Route::get('{wo}', [WorkOrderController::class, 'show'])->whereNumber('wo')->name('show');
    Route::post('{wo}/start', [WorkOrderController::class, 'start'])->whereNumber('wo')->name('start');
    Route::post('{wo}/submit', [WorkOrderController::class, 'submit'])->whereNumber('wo')->name('submit');
    Route::post('{wo}/evidence', [WorkOrderEvidenceController::class, 'store'])->whereNumber('wo')->middleware('throttle:api-upload')->name('evidence.store');
    Route::delete('{wo}/evidence/{mediaId}', [WorkOrderEvidenceController::class, 'destroy'])->whereNumber(['wo', 'mediaId'])->name('evidence.destroy');
    Route::post('{wo}/items', [WorkOrderItemController::class, 'store'])->whereNumber('wo')->name('items.store');
    Route::delete('{wo}/items/{item}', [WorkOrderItemController::class, 'destroy'])->whereNumber(['wo', 'item'])->name('items.destroy');
});
