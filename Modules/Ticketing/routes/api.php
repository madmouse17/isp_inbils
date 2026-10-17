<?php

use Illuminate\Support\Facades\Route;
use Modules\Ticketing\Http\Controllers\Api\V1\Customer\TicketCategoryController as CustomerTicketCategoryController;
use Modules\Ticketing\Http\Controllers\Api\V1\Customer\TicketCommentController as CustomerTicketCommentController;
use Modules\Ticketing\Http\Controllers\Api\V1\Customer\TicketController as CustomerTicketController;
use Modules\Ticketing\Http\Controllers\Api\V1\Technician\TicketCommentController as TechnicianTicketCommentController;
use Modules\Ticketing\Http\Controllers\Api\V1\Technician\TicketController as TechnicianTicketController;
use Modules\Ticketing\Http\Controllers\Api\V1\TicketAttachmentController;

Route::middleware('api.customer')->prefix('customer')->name('customer.')->group(function (): void {
    Route::get('ticket-categories', CustomerTicketCategoryController::class)->name('ticket-categories.index');
    Route::get('tickets', [CustomerTicketController::class, 'index'])->name('tickets.index');
    Route::post('tickets', [CustomerTicketController::class, 'store'])->name('tickets.store');
    Route::get('tickets/{id}', [CustomerTicketController::class, 'show'])->whereUlid('id')->name('tickets.show');
    Route::post('tickets/{id}/comments', CustomerTicketCommentController::class)->whereUlid('id')->name('tickets.comments.store');
    Route::post('tickets/{id}/attachments', [TicketAttachmentController::class, 'store'])->whereUlid('id')->middleware('throttle:api-upload')->name('tickets.attachments.store');
    Route::delete('tickets/{id}/attachments/{media}', [TicketAttachmentController::class, 'destroy'])->whereUlid(['id', 'media'])->name('tickets.attachments.destroy');
});

Route::middleware('api.technician')->prefix('technician')->name('technician.')->group(function (): void {
    Route::get('tickets/{id}', [TechnicianTicketController::class, 'show'])->whereUlid('id')->name('tickets.show');
    Route::post('tickets/{id}/start', [TechnicianTicketController::class, 'start'])->whereUlid('id')->name('tickets.start');
    Route::post('tickets/{id}/resolve', [TechnicianTicketController::class, 'resolve'])->whereUlid('id')->name('tickets.resolve');
    Route::post('tickets/{id}/comments', TechnicianTicketCommentController::class)->whereUlid('id')->name('tickets.comments.store');
    Route::post('tickets/{id}/attachments', [TicketAttachmentController::class, 'store'])->whereUlid('id')->middleware('throttle:api-upload')->name('tickets.attachments.store');
    Route::delete('tickets/{id}/attachments/{media}', [TicketAttachmentController::class, 'destroy'])->whereUlid(['id', 'media'])->name('tickets.attachments.destroy');
});
