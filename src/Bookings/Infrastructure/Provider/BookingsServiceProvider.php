<?php

declare(strict_types=1);

namespace Bookings\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class BookingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
