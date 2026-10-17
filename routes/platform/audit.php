<?php

use App\Http\Controllers\Platform\AuditController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])
    ->get('audit', AuditController::class)
    ->name('audit.index');
