<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Organization\Presentation\Http\Controller\BranchController;
use Organization\Presentation\Http\Controller\CompanyController;
use Organization\Presentation\Http\Controller\CustomFieldController;
use Organization\Presentation\Http\Controller\SettingsController;
use Organization\Presentation\Http\Controller\WarehouseController;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('companies', [CompanyController::class, 'index'])->middleware('permission:organization.company.view');
    Route::post('companies', [CompanyController::class, 'store'])->middleware('permission:organization.company.manage');
    Route::get('companies/{companyId}', [CompanyController::class, 'show'])->middleware('permission:organization.company.view');
    Route::put('companies/{companyId}', [CompanyController::class, 'update'])->middleware('permission:organization.company.manage');

    // Listing branches/warehouses needs no permission: it only returns the
    // ones the caller is already granted, and every screen needs the selector.
    Route::get('branches', [BranchController::class, 'index']);
    Route::post('branches', [BranchController::class, 'store'])->middleware('permission:organization.branch.manage');
    Route::get('branches/{branchId}', [BranchController::class, 'show']);
    Route::put('branches/{branchId}', [BranchController::class, 'update'])->middleware('permission:organization.branch.manage');

    Route::get('warehouses', [WarehouseController::class, 'index']);
    Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:organization.warehouse.manage');
    Route::put('warehouses/{warehouseId}', [WarehouseController::class, 'update'])->middleware('permission:organization.warehouse.manage');

    // Reading field definitions is open to every signed-in user: the forms need them.
    Route::get('custom-fields', [CustomFieldController::class, 'index']);
    Route::post('custom-fields', [CustomFieldController::class, 'store'])->middleware('permission:organization.settings.manage');
    Route::put('custom-fields/{fieldId}', [CustomFieldController::class, 'update'])->middleware('permission:organization.settings.manage');

    Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:organization.settings.view');
    Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:organization.settings.manage');
});
