<?php

use Illuminate\Support\Facades\Route;
use Modules\NetworkAsset\Http\Controllers\Api\V1\Customer\SubscriptionConnectionController;

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function (): void {
    Route::get('subscriptions/{id}/connection', SubscriptionConnectionController::class)->whereUlid('id')->name('subscriptions.connection');
});
