<?php

declare(strict_types=1);

use Banking\Presentation\Http\Controller\BankingController;
use Illuminate\Support\Facades\Route;

// Part of the finance module's plan entitlement.
Route::middleware(['auth:sanctum', 'module:finance'])->group(function (): void {
    $view = 'permission:banking.view';
    $manage = 'permission:banking.manage';

    Route::get('bank-accounts', [BankingController::class, 'accounts'])->middleware('permission:banking.view|banking.cheque.manage');
    Route::post('bank-accounts', [BankingController::class, 'storeAccount'])->middleware($manage);
    Route::put('bank-accounts/{id}', [BankingController::class, 'updateAccount'])->middleware($manage);

    Route::get('bank-transfers', [BankingController::class, 'transfers'])->middleware($view);
    Route::post('bank-transfers', [BankingController::class, 'storeTransfer'])->middleware([$manage, 'idempotent']);

    Route::get('cheques', [BankingController::class, 'cheques'])->middleware('permission:banking.view|banking.cheque.manage');
    Route::post('cheques', [BankingController::class, 'storeCheque'])->middleware(['permission:banking.cheque.manage', 'idempotent']);
    Route::post('cheques/{id}/{action}', [BankingController::class, 'chequeAction'])
        ->whereIn('action', ['deposit', 'clear', 'bounce', 'cancel'])->middleware(['permission:banking.cheque.manage', 'idempotent']);

    $rec = 'permission:banking.reconcile';
    Route::get('bank-accounts/{bankAccountId}/reconciliations', [BankingController::class, 'reconciliations'])->middleware($rec);
    Route::post('bank-accounts/{bankAccountId}/reconciliations', [BankingController::class, 'startReconciliation'])->middleware($rec);
    Route::get('bank-reconciliations/{id}', [BankingController::class, 'reconciliation'])->middleware($rec);
    Route::put('bank-reconciliations/{id}/lines', [BankingController::class, 'tick'])->middleware($rec);
    Route::post('bank-reconciliations/{id}/complete', [BankingController::class, 'complete'])->middleware($rec);
});
