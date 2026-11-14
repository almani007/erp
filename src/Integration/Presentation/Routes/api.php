<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Integration\Presentation\Http\Controller\IntegrationController as C;

Route::middleware('auth:sanctum')->prefix('integrations')->group(function (): void {
    $manage = 'permission:integration.manage';

    Route::get('fbr/settings', [C::class, 'fbrSettings'])->middleware($manage);
    Route::put('fbr/settings', [C::class, 'saveFbrSettings'])->middleware($manage);
    Route::get('fbr/submissions', [C::class, 'fiscalSubmissions'])->middleware('permission:integration.manage|sales.sale.view');
    Route::post('fbr/submissions/{id}/retry', [C::class, 'retrySubmission'])->middleware($manage);
    Route::post('fbr/sales/{saleId}', [C::class, 'submitSale'])->middleware('permission:sales.sale.create|integration.manage');

    Route::get('webhooks', [C::class, 'webhooks'])->middleware($manage);
    Route::get('webhooks/event-types', [C::class, 'eventTypes'])->middleware($manage);
    Route::post('webhooks', [C::class, 'saveWebhook'])->middleware($manage);
    Route::put('webhooks/{id}', [C::class, 'saveWebhook'])->middleware($manage);
    Route::get('webhooks/{id}/deliveries', [C::class, 'deliveries'])->middleware($manage);

    Route::get('api-clients', [C::class, 'apiClients'])->middleware($manage);
    Route::post('api-clients', [C::class, 'createApiClient'])->middleware($manage);
    Route::post('api-clients/{id}/revoke', [C::class, 'revokeApiClient'])->middleware($manage);

    Route::get('messaging/settings', [C::class, 'messagingSettings'])->middleware($manage);
    Route::put('messaging/settings', [C::class, 'saveMessagingSettings'])->middleware($manage);
    Route::get('messages', [C::class, 'messages'])->middleware('permission:integration.manage|integration.message.send');
    Route::post('messages', [C::class, 'send'])->middleware('permission:integration.message.send');

    Route::get('share/sales/{saleId}', [C::class, 'shareSale'])->middleware('permission:integration.message.send|sales.sale.view');

    Route::get('import/{entity}/template', [C::class, 'importTemplate'])->middleware('permission:integration.import');
    Route::post('import/{entity}', [C::class, 'import'])->middleware('permission:integration.import');
});
