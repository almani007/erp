<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Records\Presentation\Http\Controller\RecordController;
use Records\Presentation\Http\Controller\RecordTypeController;

Route::middleware(['auth:sanctum', 'module:records'])->group(function (): void {
    // Definitions are readable by every signed-in user: the menu is built from them.
    Route::get('record-types', [RecordTypeController::class, 'index']);
    Route::post('record-types', [RecordTypeController::class, 'store'])->middleware('permission:organization.settings.manage');
    Route::put('record-types/{typeId}', [RecordTypeController::class, 'update'])->middleware('permission:organization.settings.manage');

    Route::get('records/{typeKey}', [RecordController::class, 'index'])->middleware('permission:records.view');
    Route::post('records/{typeKey}', [RecordController::class, 'store'])->middleware('permission:records.manage');
    Route::put('records/{typeKey}/{recordId}', [RecordController::class, 'update'])->middleware('permission:records.manage');
});
