<?php

declare(strict_types=1);

namespace Assets\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class AssetsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
