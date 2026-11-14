<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Provider;

use Identity\Infrastructure\Audit\DatabaseAuditTrail;
use Identity\Infrastructure\Persistence\Eloquent\PersonalAccessToken;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Shared\Domain\Contract\AuditTrail;

final class IdentityServiceProvider extends ServiceProvider
{
    /** Role that implicitly holds every permission inside its own tenant. */
    public const string OWNER_ROLE = 'tenant_owner';

    public function register(): void
    {
        $this->app->scoped(AuditTrail::class, DatabaseAuditTrail::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Persistence/Migrations');

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Tenant isolation is enforced by the data layer, so "all permissions"
        // here can never reach beyond the owner's own tenant.
        Gate::before(static fn ($user, string $ability): ?bool => $user->hasRole(self::OWNER_ROLE) ? true : null);
    }
}
