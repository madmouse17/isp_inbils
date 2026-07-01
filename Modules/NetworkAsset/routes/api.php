<?php

use Illuminate\Support\Facades\Route;
use Modules\NetworkAsset\Http\Controllers\NetworkAssetController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('networkassets', NetworkAssetController::class)->names('networkasset');
});
