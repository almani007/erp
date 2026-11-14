<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Provider;

use Finance\Application\PostingAccounts;
use Illuminate\Support\ServiceProvider;

final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(PostingAccounts::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
