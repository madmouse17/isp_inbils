<?php

use App\Http\Controllers\Platform\CompanyCodeCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    // Beside the `{company}` routes, which only take a ULID, so `code-check` is never read as a company.
    Route::get('companies/code-check', CompanyCodeCheckController::class)->name('companies.code-check');
});
