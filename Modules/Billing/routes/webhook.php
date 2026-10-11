<?php

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\MidtransWebhookController;

// Stateless on purpose (no web group): no session, cookie or CSRF layer exists to exempt. The
// notification signature is the only credential.
Route::post('webhooks/midtrans', MidtransWebhookController::class)->name('webhooks.midtrans');
