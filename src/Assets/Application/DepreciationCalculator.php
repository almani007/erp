<?php

declare(strict_types=1);

namespace Assets\Application;

use Assets\Infrastructure\Persistence\Eloquent\FixedAsset;
use Carbon\CarbonImmutable;
use Shared\Domain\Support\Decimal;

/**
 * Monthly depreciation.
 *
 *  Straight line:    (cost − residual) ÷ useful life in months, each month.
 *  Reducing balance: (cost − accumulated) × annual rate ÷ 12, each month.
 *
 * A full month is charged from the month the asset is put in service, and
 * nothing beyond cost − residual value is ever charged. Months missed since
 * the last charge are caught up in the next run.
 */
final class DepreciationCalculator
{
    /** @return array{amount: string, months: int} charge from the month after `depreciated_to` (or the in-service month) to `$periodEnd` */
    public function charge(FixedAsset $asset, CarbonImmutable $periodEnd): array
    {
        if ($asset->status === 'disposed') {
            return ['amount' => '0', 'months' => 0];
        }

        $start = $asset->depreciated_to !== null
            ? CarbonImmutable::parse($asset->depreciated_to)->addDay()->startOfMonth()
            : CarbonImmutable::parse($asset->in_service_date)->startOfMonth();

        if ($start->greaterThan($periodEnd)) {
            return ['amount' => '0', 'months' => 0];
        }

        $months = (int) $start->diffInMonths($periodEnd->startOfMonth()) + 1;
        $accumulated = (string) $asset->accumulated_depreciation;
        $total = '0';

        for ($i = 0; $i < $months; $i++) {
            $month = $this->monthly($asset, Decimal::add($accumulated, $total, 4));
            if (! Decimal::isPositive($month)) {
                break;
            }
            $total = Decimal::add($total, $month, 4);
        }

        return ['amount' => Decimal::round($total, 2), 'months' => $months];
    }

    /** One month's charge given what has been charged so far, capped at what is left. */
    public function monthly(FixedAsset $asset, string $accumulated): string
    {
        $depreciable = Decimal::sub((string) $asset->cost, (string) $asset->residual_value, 4);
        $left = Decimal::sub($depreciable, $accumulated, 4);
        if (! Decimal::isPositive($left)) {
            return '0';
        }

        $month = $asset->method === 'reducing_balance'
            ? Decimal::div(Decimal::mul(Decimal::sub((string) $asset->cost, $accumulated, 4), (string) $asset->rate_percent, 6), '1200', 4)
            : Decimal::div($depreciable, (string) max(1, (int) $asset->useful_life_months), 4);

        return Decimal::cmp($month, $left) > 0 ? $left : $month;
    }

    /**
     * Month-by-month projection from the next charge, for the asset card.
     *
     * @return list<array{month: string, charge: string, accumulated: string, book_value: string}>
     */
    public function schedule(FixedAsset $asset, int $limit = 600): array
    {
        $rows = [];
        $accumulated = (string) $asset->accumulated_depreciation;
        $month = $asset->depreciated_to !== null
            ? CarbonImmutable::parse($asset->depreciated_to)->addDay()->startOfMonth()
            : CarbonImmutable::parse($asset->in_service_date)->startOfMonth();

        while (count($rows) < $limit && $asset->status !== 'disposed') {
            $charge = $this->monthly($asset, $accumulated);
            if (! Decimal::isPositive(Decimal::round($charge, 2))) {
                break;
            }
            $accumulated = Decimal::add($accumulated, $charge, 4);
            $rows[] = [
                'month' => $month->format('Y-m'),
                'charge' => Decimal::round($charge, 2),
                'accumulated' => Decimal::round($accumulated, 2),
                'book_value' => Decimal::round(Decimal::sub((string) $asset->cost, $accumulated, 4), 2),
            ];
            $month = $month->addMonth();
        }

        return $rows;
    }
}
