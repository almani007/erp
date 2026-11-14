<?php

declare(strict_types=1);

namespace Production\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
