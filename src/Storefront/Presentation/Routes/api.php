<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Storefront\Presentation\Http\Controller\StorefrontController as S;

// Public: no login. Limited per visitor so the store cannot be flooded with fake orders.
Route::prefix('public/stores/{slug}')->middleware('throttle:60,1')->group(function (): void {
    Route::get('/', [S::class, 'show']);
    Route::get('slots', [S::class, 'slots']);
    Route::post('orders', [S::class, 'order'])->middleware('throttle:6,10');
    Route::post('bookings', [S::class, 'book'])->middleware('throttle:6,10');
});

Route::middleware(['auth:sanctum', 'permission:organization.settings.manage'])->group(function (): void {
    Route::get('storefront/settings', [S::class, 'settings']);
    Route::put('storefront/settings', [S::class, 'saveSettings']);
});
