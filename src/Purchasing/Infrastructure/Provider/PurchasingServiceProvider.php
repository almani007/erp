<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
