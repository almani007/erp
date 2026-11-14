<?php

declare(strict_types=1);

namespace Integration\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Integration\Infrastructure\Outbox\DatabaseEventOutbox;
use Shared\Domain\Contract\EventOutbox;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(EventOutbox::class, DatabaseEventOutbox::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
