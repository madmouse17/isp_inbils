<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Setup\SetupWizardController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Fallback dashboard redirect to admin dashboard
Route::get('/dashboard', fn () => redirect()->route('admin.dashboard'))
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified', 'require.no.company'])->prefix('setup')->name('setup.')->group(function () {
    Route::get('/', [SetupWizardController::class, 'index'])->name('index');
    Route::post('/', [SetupWizardController::class, 'store'])->name('store');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::get('/admin/components', fn () => Inertia::render('Admin/Components'));
});

require __DIR__.'/auth.php';
