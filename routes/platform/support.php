<?php

use App\Http\Controllers\Platform\SupportActionsController;
use App\Http\Controllers\Platform\SupportFailuresController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])
    ->prefix('companies/{company}')
    ->where(['company' => '[0-9]+'])
    ->group(function () {
        Route::get('failed-jobs', [SupportFailuresController::class, 'jobs'])->name('companies.failed-jobs');
        Route::get('failed-notifications', [SupportFailuresController::class, 'notifications'])->name('companies.failed-notifications');

        Route::prefix('users/{user}')->where(['user' => '[0-9]+'])->group(function () {
            Route::post('two-factor/reset', [SupportActionsController::class, 'resetTwoFactor'])
                ->middleware('platform.password.confirm')
                ->name('companies.users.two-factor.reset');
            Route::post('invitation', [SupportActionsController::class, 'resendInvitation'])
                ->name('companies.users.invitation.store');
            Route::post('unlock', [SupportActionsController::class, 'unlock'])
                ->name('companies.users.unlock');
        });
    });
