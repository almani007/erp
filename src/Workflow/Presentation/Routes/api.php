<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workflow\Presentation\Http\Controller\WorkflowController as W;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('approval-rules', [W::class, 'rules'])->middleware('permission:workflow.rules.manage');
    Route::post('approval-rules', [W::class, 'saveRule'])->middleware('permission:workflow.rules.manage');
    Route::put('approval-rules/{id}', [W::class, 'saveRule'])->middleware('permission:workflow.rules.manage');

    // Anyone signed in sees their own requests and, if their role is up, the ones waiting for them.
    Route::get('approval-requests', [W::class, 'requests']);
    Route::post('approval-requests/{id}/decide', [W::class, 'decide']);
    Route::post('approval-requests/{id}/cancel', [W::class, 'cancel']);

    Route::get('notifications', [W::class, 'notifications']);
    Route::post('notifications/read', [W::class, 'markRead']);
});
