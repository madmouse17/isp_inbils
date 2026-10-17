<?php

use App\Http\Controllers\Platform\ExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::post('companies/{company}/exports', [ExportController::class, 'store'])
        ->where(['company' => '[0-9]+'])
        ->middleware('platform.password.confirm')
        ->name('companies.exports.store');

    Route::get('exports', [ExportController::class, 'index'])->name('exports.index');
    Route::get('exports/{tenantExport}/download', [ExportController::class, 'download'])
        ->where(['tenantExport' => '[0-9]+'])
        ->middleware('signed')
        ->name('exports.download');
});
