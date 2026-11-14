<?php

declare(strict_types=1);

namespace Organization\Infrastructure\Provider;

use Illuminate\Support\ServiceProvider;
use Organization\Domain\Contract\BranchContext;
use Shared\Domain\Contract\TenantContext;
use Organization\Infrastructure\Context\RequestBranchContext;
use Organization\Infrastructure\Context\RequestTenantContext;
use Organization\Infrastructure\Sequence\LockedCounterDocumentNumbers;
use Organization\Infrastructure\Settings\DatabaseSettings;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\Settings;

final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, RequestTenantContext::class);
        $this->app->scoped(BranchContext::class, RequestBranchContext::class);
        $this->app->scoped(Settings::class, DatabaseSettings::class);
        $this->app->scoped(DocumentNumbers::class, LockedCounterDocumentNumbers::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');
    }
}
