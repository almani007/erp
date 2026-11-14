<?php

declare(strict_types=1);

namespace People\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class PeopleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
