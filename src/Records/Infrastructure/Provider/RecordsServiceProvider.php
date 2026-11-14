<?php

declare(strict_types=1);

namespace Records\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class RecordsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
