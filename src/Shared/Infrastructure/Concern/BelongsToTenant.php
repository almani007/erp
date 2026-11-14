<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Concern;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Shared\Domain\Contract\TenantContext;

/**
 * Row-level tenant isolation for Eloquent models.
 *
 * Every query is constrained to the tenant of the current context (the
 * authenticated user for HTTP requests, or an explicitly set tenant for
 * console/queue work), and new rows are stamped with it.
 *
 * When no tenant can be resolved the scope matches nothing instead of
 * everything: an unscoped read must be asked for explicitly with
 * `withoutGlobalScope('tenant')`.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', static function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
        });

        static::creating(static function (Model $model): void {
            // The tenant is never taken from caller-supplied attributes.
            $model->setAttribute('tenant_id', app(TenantContext::class)->requireId());
        });
    }
}
