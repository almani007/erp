<?php

declare(strict_types=1);

/**
 * Each domain context owns its own routes. This file only stitches them
 * together, so adding an endpoint never means editing a shared router
 * file that every context has to agree on.
 *
 * Everything here is served under /api/v1 (see bootstrap/app.php).
 */

use Illuminate\Support\Facades\Route;

foreach ([
    'Identity',
    'Organization',
    'Catalog',
    'Inventory',
    'Finance',
    'Sales',
    'Purchasing',
    'Reporting',
    'Integration',
    'Production',
    'People',
    'Assets',
    'Records',
    'Saas',
] as $context) {
    $path = dirname(__DIR__).'/src/'.$context.'/Presentation/Routes/api.php';

    if (file_exists($path)) {
        Route::group([], $path);
    }
}
