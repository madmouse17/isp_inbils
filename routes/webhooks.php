<?php

use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Stateless on purpose (no web group): no session, cookie or CSRF layer exists to exempt. Meta proves who it
// is with the verify token (GET handshake) and the app-secret signature of the raw body (POST).
Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->name('webhooks.whatsapp.receive');
