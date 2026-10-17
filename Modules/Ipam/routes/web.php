<?php

use Illuminate\Support\Facades\Route;
use Modules\Ipam\Http\Controllers\IpAddressController;
use Modules\Ipam\Http\Controllers\IpPrefixController;
use Modules\Ipam\Http\Controllers\IpReservationController;
use Modules\Ipam\Http\Controllers\SubscriptionAddressController;
use Modules\Ipam\Http\Controllers\VlanController;

Route::middleware(['auth', 'verified', 'redirect.app.only', 'require.has.company'])->prefix('admin/ipam')->name('admin.ipam.')->whereUlid(['prefix', 'subscription', 'address', 'vlan'])->group(function () {
    Route::get('prefixes', [IpPrefixController::class, 'index'])->name('prefixes.index');
    Route::post('prefixes', [IpPrefixController::class, 'store'])->name('prefixes.store');
    Route::get('prefixes/{prefix}', [IpPrefixController::class, 'show'])->name('prefixes.show');
    Route::put('prefixes/{prefix}', [IpPrefixController::class, 'update'])->name('prefixes.update');
    Route::delete('prefixes/{prefix}', [IpPrefixController::class, 'destroy'])->name('prefixes.destroy');
    Route::post('prefixes/{prefix}/reservations', [IpReservationController::class, 'store'])->name('reservations.store');

    Route::post('subscriptions/{subscription}/addresses', [SubscriptionAddressController::class, 'store'])->name('subscription-addresses.store');
    Route::delete('addresses/{address}', [IpAddressController::class, 'destroy'])->name('addresses.destroy');

    Route::get('vlans', [VlanController::class, 'index'])->name('vlans.index');
    Route::post('vlans', [VlanController::class, 'store'])->name('vlans.store');
    Route::put('vlans/{vlan}', [VlanController::class, 'update'])->name('vlans.update');
    Route::delete('vlans/{vlan}', [VlanController::class, 'destroy'])->name('vlans.destroy');
});
