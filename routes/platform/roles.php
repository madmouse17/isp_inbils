<?php

use App\Http\Controllers\Platform\RoleController;
use Illuminate\Support\Facades\Route;

// Names carry the platform. prefix of the route shell (routes/platform.php).

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');

    // Role definitions reach every tenant: each write needs a fresh platform password confirmation.
    Route::middleware('platform.password.confirm')->group(function () {
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });
});
