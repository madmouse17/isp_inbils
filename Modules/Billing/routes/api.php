<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\Api\V1\Customer\InvoiceController as CustomerInvoiceController;
use Modules\Billing\Http\Controllers\Api\V1\Customer\InvoicePayController as CustomerInvoicePayController;
use Modules\Billing\Http\Controllers\Api\V1\Customer\InvoiceReceiptController as CustomerInvoiceReceiptController;
use Modules\Billing\Http\Controllers\Api\V1\Customer\PaymentController as CustomerPaymentController;

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function (): void {
    Route::get('invoices', [CustomerInvoiceController::class, 'index'])->middleware('can:portal.invoice.view')->name('invoices.index');
    Route::get('invoices/{id}', [CustomerInvoiceController::class, 'show'])->whereNumber('id')->middleware('can:portal.invoice.view')->name('invoices.show');
    Route::get('invoices/{id}/receipt', CustomerInvoiceReceiptController::class)->whereNumber('id')->middleware(['can:portal.invoice.view', 'throttle:api-receipt'])->name('invoices.receipt');
    Route::post('invoices/{id}/pay', CustomerInvoicePayController::class)->whereNumber('id')->middleware(['can:portal.invoice.pay', 'throttle:api-pay'])->name('invoices.pay');
    Route::get('payments', [CustomerPaymentController::class, 'index'])->middleware('can:portal.invoice.view')->name('payments.index');
});
