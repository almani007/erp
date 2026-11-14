<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Restaurant\Presentation\Http\Controller\RestaurantController;

Route::middleware(['auth:sanctum', 'module:restaurant'])->prefix('restaurant')->group(function (): void {
    $serve = 'permission:restaurant.order.manage';

    Route::get('floor', [RestaurantController::class, 'floor'])->middleware($serve);
    Route::get('menu', [RestaurantController::class, 'menu'])->middleware($serve);
    Route::post('orders', [RestaurantController::class, 'open'])->middleware($serve);
    Route::get('orders/{id}', [RestaurantController::class, 'show'])->middleware($serve);
    Route::post('orders/{id}/items', [RestaurantController::class, 'addItems'])->middleware($serve);
    Route::post('orders/{id}/{action}', [RestaurantController::class, 'action'])->whereIn('action', ['send', 'bill', 'reopen', 'move', 'cancel'])->middleware($serve);
    Route::post('orders/{id}/lines/{lineId}/void', [RestaurantController::class, 'voidLine'])->middleware($serve);
    Route::post('orders/{id}/settle', [RestaurantController::class, 'settle'])->middleware(['permission:sales.sale.create', 'idempotent']);

    Route::get('kitchen', [RestaurantController::class, 'kitchen'])->middleware('permission:restaurant.kitchen.view');
    Route::post('kitchen/lines/{lineId}', [RestaurantController::class, 'kitchenStatus'])->middleware('permission:restaurant.kitchen.view');

    $setup = 'permission:restaurant.setup.manage';
    Route::get('tables', [RestaurantController::class, 'tables'])->middleware($serve);
    Route::post('tables', [RestaurantController::class, 'saveTable'])->middleware($setup);
    Route::put('tables/{id}', [RestaurantController::class, 'saveTable'])->middleware($setup);
    Route::get('modifier-groups', [RestaurantController::class, 'modifierGroups'])->middleware($serve);
    Route::post('modifier-groups', [RestaurantController::class, 'saveModifierGroup'])->middleware($setup);
    Route::put('modifier-groups/{id}', [RestaurantController::class, 'saveModifierGroup'])->middleware($setup);
});
