<?php

declare(strict_types=1);

use Bookings\Presentation\Http\Controller\BookingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:bookings'])->group(function (): void {
    $book = 'permission:bookings.manage';

    Route::get('booking-resources', [BookingController::class, 'resources'])->middleware($book);
    Route::post('booking-resources', [BookingController::class, 'saveResource'])->middleware('permission:bookings.setup');
    Route::put('booking-resources/{id}', [BookingController::class, 'saveResource'])->middleware('permission:bookings.setup');

    Route::get('appointments', [BookingController::class, 'index'])->middleware($book);
    Route::get('appointments/slots', [BookingController::class, 'slots'])->middleware($book);
    Route::get('appointments/reminders', [BookingController::class, 'reminders'])->middleware($book);
    Route::post('appointments', [BookingController::class, 'store'])->middleware($book);
    Route::put('appointments/{id}', [BookingController::class, 'update'])->middleware($book);
    Route::post('appointments/{id}/status', [BookingController::class, 'status'])->middleware($book);
    Route::post('appointments/{id}/reminded', [BookingController::class, 'markReminded'])->middleware($book);
    Route::post('appointments/{id}/bill', [BookingController::class, 'bill'])->middleware(['permission:sales.sale.create', 'idempotent']);
});
