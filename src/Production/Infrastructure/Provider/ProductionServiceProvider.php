<?php

declare(strict_types=1);

namespace Production\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Production\Application\RecipeConsumption;

final class ProductionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Recipes are looked up once per request, however many lines a sale has.
        $this->app->scoped(RecipeConsumption::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
