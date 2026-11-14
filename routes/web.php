<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Signed, expiring public links to documents sent to customers.
Route::get('/share/sale/{id}', [Integration\Presentation\Http\Controller\ShareController::class, 'sale'])
    ->middleware('signed')->name('share.sale');

// Single-page application shell. Every non-API path is handed to React Router.
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$|build/|storage/|share/).*$');
