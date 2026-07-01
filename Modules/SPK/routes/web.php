<?php

use Illuminate\Support\Facades\Route;
use Modules\SPK\Http\Controllers\WorkOrderController;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::resource('spk', WorkOrderController::class)->parameters(['spk' => 'wo']);
    Route::post('spk/{wo}/generate', [WorkOrderController::class, 'generate'])->name('spk.generate');
    Route::post('spk/{wo}/assign', [WorkOrderController::class, 'assign'])->name('spk.assign');
    Route::post('spk/{wo}/start', [WorkOrderController::class, 'start'])->name('spk.start');
    Route::post('spk/{wo}/submit', [WorkOrderController::class, 'submit'])->name('spk.submit');
    Route::post('spk/{wo}/approve', [WorkOrderController::class, 'approve'])->name('spk.approve');
    Route::post('spk/{wo}/reject', [WorkOrderController::class, 'reject'])->name('spk.reject');
    Route::post('spk/{wo}/cancel', [WorkOrderController::class, 'cancel'])->name('spk.cancel');
    Route::post('spk/{wo}/items', [WorkOrderController::class, 'addItem'])->name('spk.items.store');
    Route::delete('spk/{wo}/items/{item}', [WorkOrderController::class, 'removeItem'])->name('spk.items.destroy');
    Route::post('spk/{wo}/evidence', [WorkOrderController::class, 'uploadEvidence'])->name('spk.evidence.store');
    Route::delete('spk/{wo}/evidence/{evidence}', [WorkOrderController::class, 'removeEvidence'])->name('spk.evidence.destroy');
});
