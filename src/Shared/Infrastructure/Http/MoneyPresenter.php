<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Http;

use Shared\Domain\ValueObject\Money;

final class MoneyPresenter
{
    /**
     * Format a Money value object for JSON serialisation.
     *
     * @return array{amount: string, currency: string, formatted: string}
     */
    public static function toArray(Money $money): array
    {
        return [
            'amount'    => $money->rounded(),
            'currency'  => $money->currency->value,
            'formatted' => $money->currency->symbol() . ' ' . number_format((float) $money->rounded(), $money->currency->decimalPlaces()),
        ];
    }
}
