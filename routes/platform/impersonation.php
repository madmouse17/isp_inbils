<?php

use App\Http\Controllers\Platform\ImpersonationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::post('companies/{company}/users/{user}/impersonate', [ImpersonationController::class, 'start'])
        ->middleware('platform.password.confirm')
        ->whereUlid(['company', 'user'])
        ->name('impersonation.start');

    Route::post('impersonation/write', [ImpersonationController::class, 'write'])
        ->middleware('platform.password.confirm')
        ->name('impersonation.write');
    Route::post('impersonation/read', [ImpersonationController::class, 'read'])->name('impersonation.read');
    Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])->name('impersonation.stop');
});
