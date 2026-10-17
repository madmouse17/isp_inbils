<?php

use Illuminate\Support\Facades\Route;
use Modules\SPK\Http\Controllers\Api\V1\Customer\WorkOrderController as CustomerWorkOrderController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderEvidenceController;
use Modules\SPK\Http\Controllers\Api\V1\Technician\WorkOrderItemController;

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function (): void {
    Route::get('work-orders', [CustomerWorkOrderController::class, 'index'])->name('work-orders.index');
    Route::get('work-orders/{wo}', [CustomerWorkOrderController::class, 'show'])->whereUlid('wo')->name('work-orders.show');
});

// `can:assignedTo,wo`: another technician's SPK (and any SPK of another company) is a plain 404, never a 403.
Route::middleware(['api.technician', 'can:assignedTo,wo'])->prefix('technician/work-orders')->name('technician.work-orders.')->group(function (): void {
    Route::get('{wo}', [WorkOrderController::class, 'show'])->whereUlid('wo')->name('show');
    Route::post('{wo}/start', [WorkOrderController::class, 'start'])->whereUlid('wo')->name('start');
    Route::post('{wo}/en-route', [WorkOrderController::class, 'enRoute'])->whereUlid('wo')->name('en-route');
    Route::post('{wo}/submit', [WorkOrderController::class, 'submit'])->whereUlid('wo')->name('submit');
    Route::post('{wo}/evidence', [WorkOrderEvidenceController::class, 'store'])->whereUlid('wo')->middleware('throttle:api-upload')->name('evidence.store');
    Route::delete('{wo}/evidence/{mediaId}', [WorkOrderEvidenceController::class, 'destroy'])->whereUlid(['wo', 'mediaId'])->name('evidence.destroy');
    Route::post('{wo}/items', [WorkOrderItemController::class, 'store'])->whereUlid('wo')->name('items.store');
    Route::delete('{wo}/items/{item}', [WorkOrderItemController::class, 'destroy'])->whereUlid(['wo', 'item'])->name('items.destroy');
});
