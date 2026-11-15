<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sales\Presentation\Http\Controller\CustomerController;
use Sales\Presentation\Http\Controller\DeliveryController;
use Sales\Presentation\Http\Controller\GiftCardController;
use Sales\Presentation\Http\Controller\ReceivablesController;
use Sales\Presentation\Http\Controller\SalesOrderController;
use Sales\Presentation\Http\Controller\SaleController;
use Sales\Presentation\Http\Controller\ShiftController;

Route::middleware(['auth:sanctum', 'module:sales'])->group(function (): void {
    $sell = 'permission:sales.sale.create';

    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:sales.customer.view|sales.sale.create');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:sales.customer.manage');
    Route::put('customers/{customerId}', [CustomerController::class, 'update'])->middleware('permission:sales.customer.manage');

    Route::get('customers/{customerId}/payments', [CustomerController::class, 'payments'])->middleware('permission:sales.customer.view');
    Route::post('customers/{customerId}/payments', [CustomerController::class, 'receivePayment'])
        ->middleware(['permission:sales.payment.receive', 'idempotent']);

    Route::post('customers/{customerId}/loyalty-adjustments', [CustomerController::class, 'adjustLoyalty'])->middleware('permission:sales.customer.manage');

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
    Route::get('serials', [SaleController::class, 'serial'])->middleware('permission:sales.sale.view|sales.sale.create');
    Route::get('sales-returns', [SaleController::class, 'returns'])->middleware('permission:sales.sale.view');
    Route::post('sales/{saleId}/returns', [SaleController::class, 'storeReturn'])->middleware(['permission:sales.return.create', 'idempotent']);
    Route::post('sales/{saleId}/void', [SaleController::class, 'void'])->middleware('permission:sales.sale.void');

    // Quotations → sales orders → invoices (back office, on account).
    $orders = 'permission:sales.order.manage';
    Route::get('sales-documents', [SalesOrderController::class, 'index'])->middleware('permission:sales.order.view|sales.order.manage');
    Route::get('sales-documents/{id}', [SalesOrderController::class, 'show'])->middleware('permission:sales.order.view|sales.order.manage');
    Route::post('sales-documents', [SalesOrderController::class, 'store'])->middleware($orders);
    Route::put('sales-documents/{id}', [SalesOrderController::class, 'update'])->middleware($orders);
    Route::post('sales-documents/{id}/status', [SalesOrderController::class, 'quotationStatus'])->middleware($orders);
    Route::post('sales-documents/{id}/convert', [SalesOrderController::class, 'convert'])->middleware($orders);
    Route::post('sales-documents/{id}/cancel', [SalesOrderController::class, 'cancel'])->middleware($orders);
    Route::post('sales-documents/{id}/invoice', [SalesOrderController::class, 'invoice'])->middleware(['permission:sales.invoice.create', 'idempotent']);

    // Receivables.
    $ar = 'permission:sales.receivable.view';
    Route::get('receivables/aging', [ReceivablesController::class, 'aging'])->middleware($ar);
    Route::get('customers/{customerId}/open-invoices', [ReceivablesController::class, 'openInvoices'])->middleware('permission:sales.receivable.view|sales.payment.receive');
    Route::get('customers/{customerId}/statement', [ReceivablesController::class, 'statement'])->middleware($ar);

    // Deliveries: challans for invoices, drivers, proof of delivery, cash on delivery.
    $dlv = 'permission:sales.delivery.manage';
    Route::get('deliveries', [DeliveryController::class, 'index'])->middleware($dlv);
    Route::get('deliveries/for-invoice/{saleNo}', [DeliveryController::class, 'deliverable'])->middleware($dlv);
    Route::get('deliveries/{id}', [DeliveryController::class, 'show'])->middleware($dlv);
    Route::post('deliveries', [DeliveryController::class, 'store'])->middleware($dlv);
    Route::post('deliveries/{id}/dispatch', [DeliveryController::class, 'dispatch'])->middleware($dlv);
    Route::post('deliveries/{id}/deliver', [DeliveryController::class, 'deliver'])->middleware([$dlv, 'idempotent']);
    Route::post('deliveries/{id}/fail', [DeliveryController::class, 'fail'])->middleware($dlv);
    Route::post('deliveries/{id}/cancel', [DeliveryController::class, 'cancel'])->middleware($dlv);

    // Gift cards and vouchers.
    Route::get('gift-cards', [GiftCardController::class, 'index'])->middleware('permission:sales.giftcard.manage');
    Route::get('gift-cards/lookup/{code}', [GiftCardController::class, 'lookup'])->middleware('permission:sales.sale.create|sales.giftcard.manage');
    Route::get('gift-cards/{id}', [GiftCardController::class, 'show'])->middleware('permission:sales.giftcard.manage');
    Route::post('gift-cards', [GiftCardController::class, 'store'])->middleware(['permission:sales.giftcard.manage', 'idempotent']);
    Route::post('gift-cards/{id}/block', [GiftCardController::class, 'block'])->middleware('permission:sales.giftcard.manage');
});

// Outside the module gate: every signed-in user needs it to build the menu,
// even when the sales module is switched off for the tenant.
Route::middleware('auth:sanctum')->get('app-config', [ShiftController::class, 'config']);
