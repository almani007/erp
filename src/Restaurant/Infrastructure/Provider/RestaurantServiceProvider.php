<?php

declare(strict_types=1);

namespace Restaurant\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class RestaurantServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
