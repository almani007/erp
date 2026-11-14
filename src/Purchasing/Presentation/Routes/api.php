<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Purchasing\Presentation\Http\Controller\PurchaseOrderController;
use Purchasing\Presentation\Http\Controller\SupplierController;

Route::middleware(['auth:sanctum', 'module:purchasing'])->group(function (): void {
    Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:purchasing.supplier.view');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:purchasing.supplier.manage');
    Route::put('suppliers/{supplierId}', [SupplierController::class, 'update'])->middleware('permission:purchasing.supplier.manage');
    Route::get('suppliers/{supplierId}/payments', [SupplierController::class, 'payments'])->middleware('permission:purchasing.supplier.view');
    Route::post('suppliers/{supplierId}/payments', [SupplierController::class, 'pay'])
        ->middleware(['permission:purchasing.payment.create', 'idempotent']);

    Route::get('purchasing/reorder-suggestions', [PurchaseOrderController::class, 'reorderSuggestions'])->middleware('permission:purchasing.order.view');
    Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.order.view');
    Route::get('purchase-orders/{orderId}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.order.view');
    Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware(['permission:purchasing.order.create', 'idempotent']);
    Route::post('purchase-orders/{orderId}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchasing.order.approve');
    Route::post('purchase-orders/{orderId}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchasing.order.create');
    Route::post('purchase-orders/{orderId}/receive', [PurchaseOrderController::class, 'receive'])
        ->middleware(['permission:purchasing.receipt.create', 'idempotent']);
});
