<?php

declare(strict_types=1);

use Assets\Presentation\Http\Controller\FixedAssetController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:assets'])->group(function (): void {
    $view = 'permission:assets.view';
    $manage = 'permission:assets.manage';

    Route::get('asset-categories', [FixedAssetController::class, 'categories'])->middleware($view);
    Route::post('asset-categories', [FixedAssetController::class, 'storeCategory'])->middleware($manage);
    Route::put('asset-categories/{id}', [FixedAssetController::class, 'updateCategory'])->middleware($manage);

    Route::get('fixed-assets', [FixedAssetController::class, 'index'])->middleware($view);
    Route::get('fixed-assets/{id}', [FixedAssetController::class, 'show'])->middleware($view);
    Route::post('fixed-assets', [FixedAssetController::class, 'store'])->middleware([$manage, 'idempotent']);
    Route::put('fixed-assets/{id}', [FixedAssetController::class, 'update'])->middleware($manage);
    Route::post('fixed-assets/{id}/dispose', [FixedAssetController::class, 'dispose'])->middleware(['permission:assets.dispose', 'idempotent']);

    Route::get('depreciation-runs', [FixedAssetController::class, 'runs'])->middleware($view);
    Route::get('depreciation-runs/preview', [FixedAssetController::class, 'preview'])->middleware($view);
    Route::post('depreciation-runs', [FixedAssetController::class, 'run'])->middleware(['permission:assets.depreciation.run', 'idempotent']);
    Route::post('depreciation-runs/{runId}/reverse', [FixedAssetController::class, 'reverse'])->middleware('permission:assets.depreciation.run');
});
