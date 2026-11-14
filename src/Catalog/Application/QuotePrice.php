<?php

declare(strict_types=1);

namespace Catalog\Application;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Shared\Domain\Support\Decimal;

/**
 * The single place a sale line is priced and taxed, so the POS preview and
 * the committed sale can never disagree (POS-003).
 *
 * This release prices from the product's default sale price. Price lists,
 * promotions and coupons have their tables in place but are not applied yet.
 */
final class QuotePrice
{
    /** Line amounts are rounded to the currency's minor unit. */
    private const int CURRENCY_SCALE = 2;

    /**
     * @param  string|null  $overridePrice  cashier-entered unit price (caller must have checked the permission)
     * @return array{unit_price: string, quantity: string, discount: string, tax_rate: string, tax_inclusive: bool, net: string, tax: string, total: string}
     */
    public function line(Product $product, string $quantity, ?string $overridePrice = null, string $discount = '0'): array
    {
        $unitPrice = Decimal::round($overridePrice ?? (string) $product->sale_price, Decimal::MONEY_SCALE);
        $gross = Decimal::mul($unitPrice, $quantity, self::CURRENCY_SCALE);
        $discount = Decimal::round($discount, self::CURRENCY_SCALE);

        if (Decimal::cmp($discount, $gross) > 0) {
            throw new \DomainException("Discount on {$product->name} is larger than the line amount.");
        }

        $afterDiscount = Decimal::sub($gross, $discount, self::CURRENCY_SCALE);

        $taxCode = $product->taxCode;
        $rate = $taxCode !== null && $taxCode->status === 'active' ? (string) $taxCode->rate : '0';
        $inclusive = $taxCode !== null && $taxCode->type === 'inclusive';

        if ($inclusive) {
            $divisor = Decimal::add('1', Decimal::div($rate, '100', 8), 8);
            $net = Decimal::div($afterDiscount, $divisor, self::CURRENCY_SCALE);
            $tax = Decimal::sub($afterDiscount, $net, self::CURRENCY_SCALE);
            $total = $afterDiscount;
        } else {
            $net = $afterDiscount;
            $tax = Decimal::mul($net, Decimal::div($rate, '100', 8), self::CURRENCY_SCALE);
            $total = Decimal::add($net, $tax, self::CURRENCY_SCALE);
        }

        return [
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'discount' => $discount,
            'tax_rate' => $rate,
            'tax_inclusive' => $inclusive,
            'net' => $net,
            'tax' => $tax,
            'total' => $total,
        ];
    }
}
