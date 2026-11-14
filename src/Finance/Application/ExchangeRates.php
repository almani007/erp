<?php

declare(strict_types=1);

namespace Finance\Application;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Support\Decimal;

/**
 * Exchange rates: units of the company's base currency per one unit of a
 * foreign currency. The rate for a date is the latest one entered on or
 * before it.
 */
final class ExchangeRates
{
    public function baseCurrency(string $companyId): string
    {
        return (string) DB::table('companies')->where('id', $companyId)->value('base_currency');
    }

    public function isForeign(string $companyId, ?string $currency): bool
    {
        return $currency !== null && $currency !== '' && strtoupper($currency) !== $this->baseCurrency($companyId);
    }

    /** The rate in force on a date, or null when none has been entered yet. */
    public function find(string $companyId, string $currency, string $date): ?string
    {
        $currency = strtoupper($currency);
        if ($currency === $this->baseCurrency($companyId)) {
            return '1';
        }
        $rate = DB::table('exchange_rates')->where('company_id', $companyId)->where('currency', $currency)
            ->where('rate_date', '<=', $date)->orderByDesc('rate_date')->value('rate');

        return $rate === null ? null : (string) $rate;
    }

    public function rate(string $companyId, string $currency, string $date): string
    {
        return $this->find($companyId, $currency, $date)
            ?? throw new \DomainException("There is no {$currency} exchange rate on or before {$date}. Enter one under Accounting → Currencies.");
    }

    /** Foreign amount → base currency, to 2 places. */
    public static function toBase(string $foreign, string $rate): string
    {
        return Decimal::round(Decimal::mul($foreign, $rate, 8), 2);
    }

    /** Base amount → foreign currency, to 2 places. */
    public static function toForeign(string $base, string $rate): string
    {
        return Decimal::round(Decimal::div($base, $rate, 8), 2);
    }
}
