<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Concern;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Uuid;

/**
 * Trait for Eloquent models that use UUID primary keys.
 *
 * @mixin Model
 */
trait HasUuid
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), Uuid::v7()->toRfc4122());
            }
        });
    }
}
