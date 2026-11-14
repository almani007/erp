<?php

declare(strict_types=1);

use Catalog\Presentation\Http\Controller\BarcodeController;
use Catalog\Presentation\Http\Controller\MasterDataController;
use Catalog\Presentation\Http\Controller\PricingController;
use Catalog\Presentation\Http\Controller\ProductController;
use Catalog\Presentation\Http\Controller\TradeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'module:catalog'])->group(function (): void {
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

    $pricing = 'permission:catalog.pricing.manage';

    Route::get('branch-prices', [PricingController::class, 'branchPrices'])->middleware($view);
    Route::post('branch-prices', [PricingController::class, 'storeBranchPrice'])->middleware($pricing);
    Route::put('branch-prices/{priceId}', [PricingController::class, 'updateBranchPrice'])->middleware($pricing);
    Route::delete('branch-prices/{priceId}', [PricingController::class, 'destroyBranchPrice'])->middleware($pricing);

    Route::get('promotions', [PricingController::class, 'promotions'])->middleware($view);
    Route::post('promotions', [PricingController::class, 'storePromotion'])->middleware($pricing);
    Route::put('promotions/{promotionId}', [PricingController::class, 'updatePromotion'])->middleware($pricing);

    Route::get('coupons', [PricingController::class, 'coupons'])->middleware($view);
    Route::post('coupons', [PricingController::class, 'storeCoupon'])->middleware($pricing);
    Route::put('coupons/{couponId}', [PricingController::class, 'updateCoupon'])->middleware($pricing);

    Route::get('products/{productId}/units', [TradeController::class, 'units'])->middleware($view)->whereUuid('productId');
    Route::post('products/{productId}/units', [TradeController::class, 'storeUnit'])->middleware($manage)->whereUuid('productId');
    Route::put('products/{productId}/units/{unitId}', [TradeController::class, 'updateUnit'])->middleware($manage)->whereUuid('productId');
    Route::delete('products/{productId}/units/{unitId}', [TradeController::class, 'destroyUnit'])->middleware($manage)->whereUuid('productId');
    Route::post('products/{productId}/variants', [TradeController::class, 'generateVariants'])->middleware($manage)->whereUuid('productId');

    Route::get('market-rates', [TradeController::class, 'rates'])->middleware($view);
    Route::post('market-rates', [TradeController::class, 'storeRate'])->middleware($pricing);
    Route::put('market-rates/{rateId}', [TradeController::class, 'updateRate'])->middleware($pricing);
});
