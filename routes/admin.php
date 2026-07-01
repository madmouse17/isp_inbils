<?php

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CustomerAddressController;
use App\Http\Controllers\Admin\CustomerContactController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EvaluationController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('company/profile', [CompanyController::class, 'editProfile'])->name('company.profile.edit');
    Route::put('company/profile', [CompanyController::class, 'updateProfile'])->name('company.profile.update');
    Route::get('company/settings', [CompanyController::class, 'editSettings'])->name('company.settings.edit');
    Route::put('company/settings', [CompanyController::class, 'updateSettings'])->name('company.settings.update');

    Route::resource('users', UserController::class);
    Route::resource('roles', RoleController::class);

    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');

    Route::group(['prefix' => 'locations', 'as' => 'locations.'], function () {
        Route::get('/', [LocationController::class, 'index'])->name('index');
        Route::post('/', [LocationController::class, 'store'])->name('store');
        Route::put('/{location}', [LocationController::class, 'update'])->name('update');
        Route::post('/{location}/move', [LocationController::class, 'move'])->name('move');
        Route::delete('/{location}', [LocationController::class, 'destroy'])->name('destroy');
    });

    Route::get('components', fn () => Inertia::render('Admin/Components'))->name('components');

    // Customer management
    Route::resource('customers', CustomerController::class);
    Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');

    Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index'])->name('customers.addresses.index');
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])->name('customers.addresses.store');
    Route::put('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])->name('customers.addresses.update');
    Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])->name('customers.addresses.destroy');

    Route::get('customers/{customer}/contacts', [CustomerContactController::class, 'index'])->name('customers.contacts.index');
    Route::post('customers/{customer}/contacts', [CustomerContactController::class, 'store'])->name('customers.contacts.store');
    Route::put('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'update'])->name('customers.contacts.update');
    Route::delete('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'destroy'])->name('customers.contacts.destroy');

    Route::get('customers/{customer}/subscriptions', [SubscriptionController::class, 'indexForCustomer'])->name('customers.subscriptions.index');
    Route::post('customers/{customer}/subscriptions', [SubscriptionController::class, 'storeForCustomer'])->name('customers.subscriptions.store');
    Route::resource('subscriptions', SubscriptionController::class)->only(['show', 'update']);
    Route::post('subscriptions/{subscription}/activate', [SubscriptionController::class, 'activate'])->name('subscriptions.activate');
    Route::post('subscriptions/{subscription}/suspend', [SubscriptionController::class, 'suspend'])->name('subscriptions.suspend');
    Route::post('subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])->name('subscriptions.reactivate');
    Route::post('subscriptions/{subscription}/terminate', [SubscriptionController::class, 'terminate'])->name('subscriptions.terminate');

    // Employee evaluations
    Route::resource('evaluations', EvaluationController::class);
});
