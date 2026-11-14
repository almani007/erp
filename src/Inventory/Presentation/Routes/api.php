<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inventory\Presentation\Http\Controller\StockAdjustmentController;
use Inventory\Presentation\Http\Controller\StockController;
use Inventory\Presentation\Http\Controller\StockTransferController;

Route::middleware('auth:sanctum')->group(function (): void {
    $view = 'permission:inventory.stock.view';

    Route::get('inventory/balances', [StockController::class, 'balances'])->middleware($view);
    Route::get('inventory/movements', [StockController::class, 'movements'])->middleware($view);

    Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware($view);
    Route::get('stock-adjustments/{adjustmentId}', [StockAdjustmentController::class, 'show'])->middleware($view);
    Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware(['permission:inventory.adjustment.create', 'idempotent']);

    Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware($view);
    Route::post('stock-transfers', [StockTransferController::class, 'store'])
        ->middleware(['permission:inventory.transfer.manage', 'idempotent']);
    Route::post('stock-transfers/{transferId}/receive', [StockTransferController::class, 'receive'])
        ->middleware('permission:inventory.transfer.manage');
});
