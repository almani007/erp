<?php

declare(strict_types=1);

use Finance\Presentation\Http\Controller\AccountController;
use Finance\Presentation\Http\Controller\FinancialReportController;
use Finance\Presentation\Http\Controller\JournalController;
use Finance\Presentation\Http\Controller\PeriodController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:finance'])->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:finance.account.view');
    Route::post('accounts', [AccountController::class, 'store'])->middleware('permission:finance.account.manage');
    Route::put('accounts/{accountId}', [AccountController::class, 'update'])->middleware('permission:finance.account.manage');
    Route::get('posting-accounts', [AccountController::class, 'postingAccounts'])->middleware('permission:finance.account.view');
    Route::put('posting-accounts', [AccountController::class, 'mapPostingAccount'])->middleware('permission:finance.account.manage');

    Route::get('journal-entries', [JournalController::class, 'index'])->middleware('permission:finance.journal.view');
    Route::get('journal-entries/{journalId}', [JournalController::class, 'show'])->middleware('permission:finance.journal.view');
    Route::post('journal-entries', [JournalController::class, 'store'])->middleware(['permission:finance.journal.create', 'idempotent']);
    Route::post('vouchers', [JournalController::class, 'voucher'])->middleware(['permission:finance.voucher.create', 'idempotent']);
    Route::post('journal-entries/{journalId}/reverse', [JournalController::class, 'reverse'])->middleware('permission:finance.journal.reverse');

    Route::get('accounting-periods', [PeriodController::class, 'index'])->middleware('permission:finance.account.view');
    Route::post('accounting-periods', [PeriodController::class, 'store'])->middleware('permission:finance.period.manage');
    Route::post('accounting-periods/{periodId}/close', [PeriodController::class, 'close'])->middleware('permission:finance.period.manage');
    Route::post('accounting-periods/{periodId}/reopen', [PeriodController::class, 'reopen'])->middleware('permission:finance.period.manage');

    Route::prefix('reports')->middleware('permission:finance.report.view')->group(function (): void {
        Route::get('trial-balance', [FinancialReportController::class, 'trialBalance']);
        Route::get('profit-loss', [FinancialReportController::class, 'profitLoss']);
        Route::get('balance-sheet', [FinancialReportController::class, 'balanceSheet']);
        Route::get('general-ledger', [FinancialReportController::class, 'generalLedger']);
    });
});
