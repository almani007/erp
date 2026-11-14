<?php

declare(strict_types=1);

namespace Rentals\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class RentalsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
