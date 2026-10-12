<?php

use App\Http\Controllers\Admin\ApprovalInboxController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CompanySwitchController;
use App\Http\Controllers\Admin\CustomerAccessLinkController;
use App\Http\Controllers\Admin\CustomerAddressController;
use App\Http\Controllers\Admin\CustomerContactController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DispatchQueueController;
use App\Http\Controllers\Admin\DocumentController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\EvaluationController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\MediaController;
use App\Http\Controllers\Admin\MyWorkController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\NumberSequenceController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\PermissionController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserTwoFactorResetController;
use App\Http\Controllers\Admin\VehicleController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('approvals', [ApprovalInboxController::class, 'index'])->name('approvals.index');
    Route::get('dispatch', [DispatchQueueController::class, 'index'])->name('dispatch.index');
    Route::post('dispatch/assign', [DispatchQueueController::class, 'assign'])->name('dispatch.assign');
    Route::get('my-work', [MyWorkController::class, 'index'])->name('my-work.index');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('company/profile', [CompanyController::class, 'editProfile'])->name('company.profile.edit');
    Route::put('company/profile', [CompanyController::class, 'updateProfile'])->name('company.profile.update');
    Route::get('company/settings', [CompanyController::class, 'editSettings'])->name('company.settings.edit');
    Route::put('company/settings', [CompanyController::class, 'updateSettings'])->name('company.settings.update');
    Route::post('company/switch', CompanySwitchController::class)->name('company.switch');

    Route::get('users/export', [UserController::class, 'export'])->name('users.export');
    Route::resource('users', UserController::class);
    Route::post('users/{user}/two-factor/reset', UserTwoFactorResetController::class)->name('users.two-factor.reset');
    Route::get('roles/export', [RoleController::class, 'export'])->name('roles.export');
    Route::resource('roles', RoleController::class);

    Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');

    Route::prefix('locations')->name('locations.')->group(function () {
        Route::get('/', [LocationController::class, 'index'])->name('index');
        Route::post('/', [LocationController::class, 'store'])->name('store');
        Route::put('/{location}', [LocationController::class, 'update'])->name('update');
        Route::post('/{location}/move', [LocationController::class, 'move'])->name('move');
        Route::delete('/{location}', [LocationController::class, 'destroy'])->name('destroy');
    });

    Route::get('components', function () {
        // Developer-only gallery. Fail-closed by default: not even an admin
        // may open it outside a non-production environment. No secrets or
        // debug payloads live here, so 404 keeps the route obscure.
        abort_unless(app()->environment(['local', 'testing']), 404);

        return Inertia::render('Admin/Components');
    })->middleware('can:developer.components.view')->name('components');

    // Customer management
    Route::get('customers/{customer}/addresses/export', [CustomerAddressController::class, 'export'])->name('customers.addresses.export');
    Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
    Route::resource('customers', CustomerController::class)->parameters(['customers' => 'customer']);
    Route::post('customers/{customer}/access-link', CustomerAccessLinkController::class)->middleware('throttle:3,1')->name('customers.access-link');

    Route::get('customers/{customer}/addresses', [CustomerAddressController::class, 'index'])->name('customers.addresses.index');
    Route::post('customers/{customer}/addresses', [CustomerAddressController::class, 'store'])->name('customers.addresses.store');
    Route::put('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'update'])->name('customers.addresses.update');
    Route::delete('customers/{customer}/addresses/{address}', [CustomerAddressController::class, 'destroy'])->name('customers.addresses.destroy');

    Route::get('customers/{customer}/contacts/export', [CustomerContactController::class, 'export'])->name('customers.contacts.export');
    Route::get('customers/{customer}/contacts', [CustomerContactController::class, 'index'])->name('customers.contacts.index');
    Route::post('customers/{customer}/contacts', [CustomerContactController::class, 'store'])->name('customers.contacts.store');
    Route::put('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'update'])->name('customers.contacts.update');
    Route::delete('customers/{customer}/contacts/{contact}', [CustomerContactController::class, 'destroy'])->name('customers.contacts.destroy');

    Route::get('customers/{customer}/subscriptions/export', [SubscriptionController::class, 'export'])->name('customers.subscriptions.export');
    Route::get('customers/{customer}/subscriptions', [SubscriptionController::class, 'indexForCustomer'])->name('customers.subscriptions.index');
    Route::post('customers/{customer}/subscriptions', [SubscriptionController::class, 'storeForCustomer'])->name('customers.subscriptions.store');
    Route::resource('subscriptions', SubscriptionController::class)->only(['show', 'update']);
    Route::post('subscriptions/{subscription}/activate', [SubscriptionController::class, 'activate'])->name('subscriptions.activate');
    Route::post('subscriptions/{subscription}/suspend', [SubscriptionController::class, 'suspend'])->name('subscriptions.suspend');
    Route::post('subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])->name('subscriptions.reactivate');
    Route::post('subscriptions/{subscription}/block', [SubscriptionController::class, 'block'])->name('subscriptions.block');
    Route::post('subscriptions/{subscription}/unblock', [SubscriptionController::class, 'unblock'])->name('subscriptions.unblock');
    Route::post('subscriptions/{subscription}/terminate', [SubscriptionController::class, 'terminate'])->name('subscriptions.terminate');

    // Employee evaluations
    Route::get('evaluations/export', [EvaluationController::class, 'export'])->name('evaluations.export');
    Route::resource('evaluations', EvaluationController::class);

    // Organization & Employee
    Route::get('organizations/export', [OrganizationController::class, 'export'])->name('organizations.export');
    Route::resource('organizations', OrganizationController::class)
        ->parameters(['organizations' => 'organization_unit'])
        ->except(['create', 'edit', 'show']);
    Route::post('organizations/{organization_unit}/move', [OrganizationController::class, 'move'])->name('organizations.move');
    Route::get('employees/export', [EmployeeController::class, 'export'])->name('employees.export');
    Route::resource('employees', EmployeeController::class)->except(['create', 'edit', 'show']);
    Route::get('vehicles/export', [VehicleController::class, 'export'])->name('vehicles.export');
    Route::resource('vehicles', VehicleController::class)->except(['create', 'edit', 'show']);

    // Number Sequences
    Route::get('number-sequences/export', [NumberSequenceController::class, 'export'])->name('number-sequences.export');
    Route::resource('number-sequences', NumberSequenceController::class)->only(['index', 'update']);

    // Documents (types + media)
    Route::get('documents/export', [DocumentController::class, 'export'])->name('documents.export');
    Route::resource('documents', DocumentController::class, ['parameters' => ['documents' => 'document_type']])->except(['create', 'edit', 'show']);
    Route::post('documents/media', [DocumentController::class, 'uploadMedia'])->name('documents.media.store');
    Route::delete('documents/media/{media}', [DocumentController::class, 'deleteMedia'])->name('documents.media.destroy');

    // Uploaded files (ticket attachments, SPK evidence, customer documents): authorized per owner
    Route::get('media/{media}', [MediaController::class, 'show'])->whereNumber('media')->name('media.show');
});
