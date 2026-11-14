<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Cast;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Shared\Domain\ValueObject\Currency;
use Shared\Domain\ValueObject\Money;

/**
 * Cast a numeric database column to a Money value object.
 *
 * Usage on the model:
 *   protected function casts(): array
 *   {
 *       return ['grand_total' => MoneyCast::class . ':currency_column'];
 *   }
 *
 * The currency is read from the specified column on the same model.
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(
        private readonly string $currencyColumn = 'currency',
    ) {}

    /** @param  Model  $model */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currencyCode = $attributes[$this->currencyColumn] ?? 'PKR';

        return new Money(
            amount:   (string) $value,
            currency: Currency::from($currencyCode),
        );
    }

    /** @param  Money|string|null  $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Money) {
            return $value->amount;
        }

        return (string) $value;
    }
}
