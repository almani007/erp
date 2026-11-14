<?php

declare(strict_types=1);

namespace Inventory\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Infrastructure\Persistence\EloquentStockLedger;
use Inventory\Infrastructure\Persistence\LotLedger;

final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(LotLedger::class);
        $this->app->scoped(StockLedger::class, EloquentStockLedger::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
