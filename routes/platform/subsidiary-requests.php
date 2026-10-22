<?php

use App\Http\Controllers\Platform\SubsidiaryRequestController;
use Illuminate\Support\Facades\Route;

// Names carry the platform. prefix of the route shell (routes/platform.php).

// Turning a request down closes it for good and mails the person who asked: the operator confirms their password first.
Route::middleware(['auth:platform', 'platform.account', 'platform.2fa', 'platform.password.confirm'])->group(function () {
    Route::post('companies/{company}/subsidiary-requests/{subsidiaryRequest}/reject', [SubsidiaryRequestController::class, 'reject'])
        ->whereUlid(['company', 'subsidiaryRequest'])
        ->name('companies.subsidiary-requests.reject');
});
