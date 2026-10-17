<?php

use App\Http\Controllers\Platform\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Platform\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Platform\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Platform\Auth\TwoFactorEnrollmentController;
use Illuminate\Support\Facades\Route;

// Names carry the platform. prefix of the route shell (routes/platform.php).

Route::middleware('guest:platform')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.login');
    Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.login.store');
});

// A signed-in admin who has no second factor yet reaches only these (and the logout): platform.2fa is left out on
// purpose, and sends everything else here. platform.account still applies: an inactive or idle session ends.
Route::middleware(['auth:platform', 'platform.account'])->group(function () {
    Route::get('two-factor/setup', [TwoFactorEnrollmentController::class, 'show'])->name('two-factor.setup');
    Route::post('two-factor/setup', [TwoFactorEnrollmentController::class, 'store'])->name('two-factor.setup.store');
    Route::post('two-factor/confirm', [TwoFactorEnrollmentController::class, 'confirm'])->name('two-factor.confirm');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->name('password.confirm.store');

    // New recovery codes end the old ones, so they need a recent password confirmation.
    Route::post('two-factor/recovery-codes', [TwoFactorEnrollmentController::class, 'regenerateRecoveryCodes'])
        ->middleware('platform.password.confirm')
        ->name('two-factor.recovery-codes');
});
