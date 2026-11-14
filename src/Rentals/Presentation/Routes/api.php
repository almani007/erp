<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Rentals\Presentation\Http\Controller\RentalController;

Route::middleware(['auth:sanctum', 'module:rentals'])->group(function (): void {
    $p = 'permission:rentals.manage';
    Route::get('rental-contracts', [RentalController::class, 'index'])->middleware($p);
    Route::post('rental-contracts', [RentalController::class, 'store'])->middleware([$p, 'idempotent']);
    Route::post('rental-contracts/bill-due', [RentalController::class, 'bill'])->middleware($p);
    Route::get('rental-contracts/{id}/invoices', [RentalController::class, 'invoices'])->middleware($p);
    Route::post('rental-contracts/{id}/return', [RentalController::class, 'returnItem'])->middleware([$p, 'idempotent']);
});
