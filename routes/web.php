<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Single-page application shell. Every non-API path is handed to React Router.
Route::view('/{any?}', 'app')->where('any', '^(?!api/|up$|build/|storage/).*$');
