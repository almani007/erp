<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sales\Presentation\Http\Controller\CustomerController;
use Sales\Presentation\Http\Controller\SaleController;
use Sales\Presentation\Http\Controller\ShiftController;

Route::middleware('auth:sanctum')->group(function (): void {
    $sell = 'permission:sales.sale.create';

    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:sales.customer.view|sales.sale.create');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:sales.customer.manage');
    Route::put('customers/{customerId}', [CustomerController::class, 'update'])->middleware('permission:sales.customer.manage');

    Route::get('pos/terminals', [ShiftController::class, 'terminals'])->middleware($sell);
    Route::post('pos/terminals', [ShiftController::class, 'storeTerminal'])->middleware('permission:sales.shift.manage');

    Route::get('pos/shifts', [ShiftController::class, 'index'])->middleware('permission:sales.shift.manage');
    Route::get('pos/shifts/current', [ShiftController::class, 'current'])->middleware($sell);
    Route::post('pos/shifts/open', [ShiftController::class, 'open'])->middleware($sell);
    Route::post('pos/shifts/current/cash-movements', [ShiftController::class, 'cashMovement'])->middleware($sell);
    Route::post('pos/shifts/current/close', [ShiftController::class, 'close'])->middleware($sell);

    Route::post('pos/quote', [SaleController::class, 'quote'])->middleware($sell);

    Route::get('sales', [SaleController::class, 'index'])->middleware('permission:sales.sale.view');
    Route::get('sales/{saleId}', [SaleController::class, 'show'])->middleware('permission:sales.sale.view|sales.sale.create');
    Route::post('sales', [SaleController::class, 'store'])->middleware([$sell, 'idempotent']);
    Route::post('sales/{saleId}/void', [SaleController::class, 'void'])->middleware('permission:sales.sale.void');
});
