<?php

use App\Http\Controllers\AppOnlyController;
use App\Http\Controllers\Operations\ReadinessController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Setup\SetupWizardController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'admin.dashboard' : 'login'));

// Fallback dashboard redirect to admin dashboard
Route::get('/dashboard', fn () => redirect()->route('admin.dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('/ready', ReadinessController::class);

Route::get('/app-only', AppOnlyController::class)->middleware(['auth', 'verified'])->name('app-only');

Route::middleware(['auth', 'verified', 'require.no.company'])->prefix('setup')->name('setup.')->group(function () {
    Route::get('/', [SetupWizardController::class, 'index'])->name('index');
    Route::post('/', [SetupWizardController::class, 'store'])->name('store');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
