<?php

declare(strict_types=1);

use Identity\Presentation\Http\Controller\AuditLogController;
use Identity\Presentation\Http\Controller\AuthController;
use Identity\Presentation\Http\Controller\RoleController;
use Identity\Presentation\Http\Controller\UserController;
use Illuminate\Support\Facades\Route;

// 10 attempts per minute per IP.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);

    Route::get('users', [UserController::class, 'index'])->middleware('permission:identity.user.view');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:identity.user.manage');
    Route::put('users/{userId}', [UserController::class, 'update'])->middleware('permission:identity.user.manage');

    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:identity.user.view');
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:identity.audit.view');
});
