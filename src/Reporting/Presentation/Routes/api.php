<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Reporting\Presentation\Http\Controller\DashboardController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('dashboard/summary', [DashboardController::class, 'summary'])->middleware('permission:reporting.dashboard.view');
    Route::get('analytics/sales-lines', [DashboardController::class, 'salesLines'])->middleware('permission:reporting.analytics.view');
});
