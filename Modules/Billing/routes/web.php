<?php

use App\Http\Middleware\EnsureCustomerAccess;
use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\Portal\PaymentReturnController;

Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->prefix('admin')->name('admin.')->whereUlid(['invoice', 'payment', 'item'])->group(function () {
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

Route::inertia('pembayaran/selesai', 'Portal/Payments/Finished')->name('payments.finished');

// Midtrans return target of payments opened before the app-only cutover (Midtrans keeps the finish URL per transaction).
// Deliberately outside redirect.app.only: a customer coming back from the hosted payment page must see its status.
Route::middleware(['auth', 'verified', 'require.has.company', EnsureCustomerAccess::class])->prefix('portal')->name('portal.')->group(function () {
    Route::get('payments/return', PaymentReturnController::class)->middleware('can:portal.invoice.view')->name('payments.return');
});
