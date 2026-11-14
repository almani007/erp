<?php

declare(strict_types=1);

namespace Banking\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class BankingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
