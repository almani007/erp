<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inventory\Presentation\Http\Controller\StockAdjustmentController;
use Inventory\Presentation\Http\Controller\StockController;
use Inventory\Presentation\Http\Controller\StockCountController;
use Inventory\Presentation\Http\Controller\StockTransferController;

Route::middleware(['auth:sanctum', 'module:inventory'])->group(function (): void {
    $view = 'permission:inventory.stock.view';

    Route::get('inventory/balances', [StockController::class, 'balances'])->middleware($view);
    Route::get('stock-availability', [StockController::class, 'availability'])->middleware('permission:inventory.stock.view|sales.order.manage');
    Route::get('inventory/reservations', [StockController::class, 'reservations'])->middleware($view);
    Route::get('inventory/movements', [StockController::class, 'movements'])->middleware($view);
    Route::get('inventory/lots', [StockController::class, 'lots'])->middleware($view);

    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware($view);
    Route::get('stock-adjustments/{adjustmentId}', [StockAdjustmentController::class, 'show'])->middleware($view);
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware(['permission:inventory.adjustment.create', 'idempotent']);

    Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware($view);
    Route::post('stock-transfers', [StockTransferController::class, 'store'])
        ->middleware(['permission:inventory.transfer.manage', 'idempotent']);
    Route::post('stock-transfers/{transferId}/receive', [StockTransferController::class, 'receive'])
        ->middleware('permission:inventory.transfer.manage');

    $count = 'permission:inventory.count.manage';

    Route::get('stock-counts', [StockCountController::class, 'index'])->middleware($view);
    Route::get('stock-counts/{countId}', [StockCountController::class, 'show'])->middleware($view);
    Route::post('stock-counts', [StockCountController::class, 'store'])->middleware($count);
    Route::put('stock-counts/{countId}/lines', [StockCountController::class, 'enter'])->middleware($count);
    Route::post('stock-counts/{countId}/post', [StockCountController::class, 'post'])->middleware($count);
    Route::post('stock-counts/{countId}/cancel', [StockCountController::class, 'cancel'])->middleware($count);
});
