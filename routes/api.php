<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Customer\ProfileController;
use App\Http\Controllers\Api\V1\Customer\SubscriptionController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Technician\MyWorkController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API v1 (customer and technician apps)
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under the `api` group with prefix api/v1 and names api.v1.*. Route files only
| name the groups defined there: api.any (a signed-in app user), api.customer and api.technician (the same, fenced
| to one app); api.session is the same guards without Idempotency-Key, for the auth endpoints that need a token.
|
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('login');
    Route::post('two-factor', [AuthController::class, 'twoFactor'])->name('two-factor');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:api-forgot-password')->name('forgot-password');

    Route::post('token/refresh', [AuthController::class, 'refresh'])->middleware('api.session')->name('token.refresh');
    // A token is enough to leave, even for a user who lost access to the app.
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
});

Route::middleware('api.any')->group(function () {
    Route::get('me', MeController::class)->name('me');
    Route::get('devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::delete('devices/{id}', [DeviceController::class, 'destroy'])->whereNumber('id')->name('devices.destroy');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->name('notifications.read');
    Route::get('media/{media}', MediaController::class)->whereNumber('media')->name('media.show');
});

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function () {
    Route::get('profile', ProfileController::class)->name('profile');
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::get('subscriptions/{id}', [SubscriptionController::class, 'show'])->whereNumber('id')->name('subscriptions.show');
});

Route::middleware('api.technician')->prefix('technician')->name('technician.')->group(function () {
    Route::get('my-work', MyWorkController::class)->name('my-work');
});
