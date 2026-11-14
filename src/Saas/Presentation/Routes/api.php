<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Saas\Presentation\Http\Controller\BusinessTemplateController;
use Saas\Presentation\Http\Controller\TenantAdminController;

// Platform operator console. Never reachable by a tenant's own users.
Route::middleware(['auth:sanctum', 'platform'])->prefix('saas')->group(function (): void {
    Route::get('tenants', [TenantAdminController::class, 'index']);
    Route::post('tenants', [TenantAdminController::class, 'store'])->middleware('idempotent');
    Route::put('tenants/{tenantId}', [TenantAdminController::class, 'update']);
    Route::post('tenants/{tenantId}/apply-template', [TenantAdminController::class, 'applyTemplate']);

    Route::get('templates', [BusinessTemplateController::class, 'index']);
    Route::post('templates', [BusinessTemplateController::class, 'store']);
    Route::put('templates/{templateId}', [BusinessTemplateController::class, 'update']);
});

// A business can browse the templates and apply one to itself (additive only).
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('business-templates', [BusinessTemplateController::class, 'available']);
    Route::post('business-templates/{code}/apply', [BusinessTemplateController::class, 'applyToOwnBusiness'])
        ->middleware('permission:organization.settings.manage');
});
