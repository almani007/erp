<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Production\Presentation\Http\Controller\ProductionOrderController;
use Production\Presentation\Http\Controller\RecipeController;

Route::middleware(['auth:sanctum', 'module:production'])->group(function (): void {
    $view = 'permission:production.view';
    $recipes = 'permission:production.recipe.manage';
    $orders = 'permission:production.order.manage';

    Route::get('recipes', [RecipeController::class, 'index'])->middleware($view);
    Route::post('recipes', [RecipeController::class, 'store'])->middleware($recipes);
    Route::put('recipes/{recipeId}', [RecipeController::class, 'update'])->middleware($recipes);
    Route::delete('recipes/{recipeId}', [RecipeController::class, 'destroy'])->middleware($recipes);

    Route::get('work-centres', [RecipeController::class, 'workCentres'])->middleware($view);
    Route::post('work-centres', [RecipeController::class, 'saveWorkCentre'])->middleware($recipes);
    Route::put('work-centres/{id}', [RecipeController::class, 'saveWorkCentre'])->middleware($recipes);

    Route::get('production-orders', [ProductionOrderController::class, 'index'])->middleware($view);
    Route::post('production-orders', [ProductionOrderController::class, 'store'])->middleware([$orders, 'idempotent']);
    Route::post('production-orders/{orderId}/complete', [ProductionOrderController::class, 'complete'])->middleware([$orders, 'idempotent']);
    Route::post('production-orders/{orderId}/cancel', [ProductionOrderController::class, 'cancel'])->middleware($orders);
});
