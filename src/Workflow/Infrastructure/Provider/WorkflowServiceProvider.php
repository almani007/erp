<?php

declare(strict_types=1);

namespace Workflow\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;

final class WorkflowServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
