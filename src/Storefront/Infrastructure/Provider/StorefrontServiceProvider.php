<?php

declare(strict_types=1);

namespace Storefront\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class StorefrontServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
