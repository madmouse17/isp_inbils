<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\MidtransWebhookController;

// Stateless on purpose (no web group): no session, cookie or CSRF layer exists to exempt. The
// notification signature is the only credential, and each company signs with its own server key.
// Each company gives Midtrans its own URL; the legacy one stays for installs that still point there, and finds the
// company from the order id.
Route::post('webhooks/midtrans', MidtransWebhookController::class)->name('webhooks.midtrans');
Route::post('webhooks/midtrans/{company}', MidtransWebhookController::class)->whereUlid('company')->name('webhooks.midtrans.company');
