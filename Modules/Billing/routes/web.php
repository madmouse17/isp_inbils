<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\Portal\InvoiceController as PortalInvoiceController;
use Modules\Billing\Http\Controllers\Portal\PasswordController as PortalPasswordController;
use Modules\Billing\Http\Controllers\Portal\PaymentReturnController;
use Modules\Billing\Http\Middleware\EnsureCustomerPortalAccess;
use Modules\Billing\Http\Middleware\EnsurePortalPasswordChanged;

Route::middleware(['auth', 'verified', 'require.has.company'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('invoices/export', [InvoiceController::class, 'export'])->name('invoices.export');
    Route::get('billing/receivables', [InvoiceController::class, 'receivables'])->name('billing.receivables');
    Route::post('invoices/create-from-spk', [InvoiceController::class, 'createFromSpk'])->name('invoices.create-from-spk');
    Route::post('invoices/generate-preview', [InvoiceController::class, 'generatePreview'])->name('invoices.generate-preview');
    Route::post('invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::resource('invoices', InvoiceController::class);
    Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    Route::post('invoices/{invoice}/payments/{payment}/reverse', [InvoiceController::class, 'reversePayment'])->name('invoices.payments.reverse');
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('invoices/{invoice}/items', [InvoiceController::class, 'addItem'])->name('invoices.items.store');
    Route::delete('invoices/{invoice}/items/{item}', [InvoiceController::class, 'removeItem'])->name('invoices.items.destroy');
});

// Customer portal: customer logins only (role + linked Customer), never the admin permission set.
Route::middleware(['auth', 'verified', 'require.has.company', EnsureCustomerPortalAccess::class])->prefix('portal')->name('portal.')->group(function () {
    Route::get('password', [PortalPasswordController::class, 'edit'])->name('password.edit');
    Route::post('password/link', [PortalPasswordController::class, 'sendLink'])->middleware('throttle:3,1,portal-password-link')->name('password.link');

    Route::middleware(EnsurePortalPasswordChanged::class)->group(function () {
        Route::get('invoices', [PortalInvoiceController::class, 'index'])->middleware('can:portal.invoice.view')->name('invoices.index');
        Route::get('invoices/{invoice}', [PortalInvoiceController::class, 'show'])->whereNumber('invoice')->middleware('can:portal.invoice.view')->name('invoices.show');
        Route::post('invoices/{invoice}/pay', [PortalInvoiceController::class, 'pay'])->whereNumber('invoice')->middleware(['can:portal.invoice.pay', 'throttle:6,1,portal-pay'])->name('invoices.pay');
        Route::get('payments/return', PaymentReturnController::class)->middleware('can:portal.invoice.view')->name('payments.return');
    });
});
