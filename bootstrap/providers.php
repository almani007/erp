<?php

declare(strict_types=1);

/**
 * Only modules that are fully wired (migrations, models, routes) are
 * registered here. See the module status table in README.md.
 */
return [
    App\Providers\AppServiceProvider::class,

    Organization\Infrastructure\Provider\OrganizationServiceProvider::class,
    Identity\Infrastructure\Provider\IdentityServiceProvider::class,
    Integration\Infrastructure\Provider\IntegrationServiceProvider::class,
    Catalog\Infrastructure\Provider\CatalogServiceProvider::class,
    Inventory\Infrastructure\Provider\InventoryServiceProvider::class,
    Finance\Infrastructure\Provider\FinanceServiceProvider::class,
    Sales\Infrastructure\Provider\SalesServiceProvider::class,
    Purchasing\Infrastructure\Provider\PurchasingServiceProvider::class,
    Reporting\Infrastructure\Provider\ReportingServiceProvider::class,
];
