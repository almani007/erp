<?php

declare(strict_types=1);

namespace Saas\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Saas\Application\Entitlements;

final class SaasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request: entitlements are read once and reused.
        $this->app->scoped(Entitlements::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
