<?php

declare(strict_types=1);

use Catalog\Presentation\Http\Controller\BarcodeController;
use Catalog\Presentation\Http\Controller\MasterDataController;
use Catalog\Presentation\Http\Controller\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    $view = 'permission:catalog.product.view';
    $manage = 'permission:catalog.product.manage';
    $master = 'permission:catalog.master.manage';

    Route::get('products', [ProductController::class, 'index'])->middleware($view);
    Route::post('products', [ProductController::class, 'store'])->middleware($manage);
    Route::get('products/lookup', [BarcodeController::class, 'lookup'])->middleware($view);
    Route::get('products/{productId}', [ProductController::class, 'show'])->middleware($view)->whereUuid('productId');
    Route::put('products/{productId}', [ProductController::class, 'update'])->middleware($manage)->whereUuid('productId');

    Route::get('categories', [MasterDataController::class, 'categories'])->middleware($view);
    Route::post('categories', [MasterDataController::class, 'storeCategory'])->middleware($master);
    Route::put('categories/{categoryId}', [MasterDataController::class, 'updateCategory'])->middleware($master);

    Route::get('brands', [MasterDataController::class, 'brands'])->middleware($view);
    Route::post('brands', [MasterDataController::class, 'storeBrand'])->middleware($master);
    Route::put('brands/{brandId}', [MasterDataController::class, 'updateBrand'])->middleware($master);

    Route::get('units', [MasterDataController::class, 'units'])->middleware($view);
    Route::post('units', [MasterDataController::class, 'storeUnit'])->middleware($master);

    Route::get('tax-codes', [MasterDataController::class, 'taxCodes'])->middleware($view);
    Route::post('tax-codes', [MasterDataController::class, 'storeTaxCode'])->middleware($master);
    Route::put('tax-codes/{taxCodeId}', [MasterDataController::class, 'updateTaxCode'])->middleware($master);
});
