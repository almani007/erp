<?php

declare(strict_types=1);

namespace Sales\Application;

use Catalog\Application\QuotePrice;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Shared\Domain\Exception\InsufficientPermission;
use Shared\Domain\Support\Decimal;

/**
 * Prices a cart on the server. The POS preview and the committed sale both
 * go through here, so what the cashier sees is what gets posted.
 */
final class PriceCart
{
    public function __construct(private readonly QuotePrice $quote) {}

    /**
     * @param  list<array{product_id: string, quantity: string|int|float, unit_price?: string|int|float|null, discount?: string|int|float|null}>  $lines
     * @param  bool  $mayOverride  caller holds sales.price.override
     * @return array{
     *     lines: list<array{product: Product, unit_price: string, quantity: string, discount: string, tax_rate: string, net: string, tax: string, total: string}>,
     *     subtotal: string, discount_total: string, tax_total: string, net_total: string, grand_total: string
     * }
     */
    public function price(array $lines, bool $mayOverride): array
    {
        $products = Product::query()
            ->with('taxCode')
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        $priced = [];
        $totals = ['discount_total' => '0', 'tax_total' => '0', 'net_total' => '0', 'grand_total' => '0'];

        foreach ($lines as $line) {
            $product = $products->get($line['product_id'])
                ?? throw new \DomainException('A product in the cart no longer exists.');

            if (! $product->status->isSellable()) {
                throw new \DomainException("{$product->name} is not available for sale.");
            }

            $override = isset($line['unit_price']) ? (string) $line['unit_price'] : null;
            $discount = (string) ($line['discount'] ?? '0');

            $changesPrice = ($override !== null && Decimal::cmp($override, (string) $product->sale_price) !== 0)
                || Decimal::isPositive($discount);

            if ($changesPrice && ! $mayOverride) {
                throw InsufficientPermission::for('sales.price.override');
            }

            $q = $this->quote->line($product, (string) $line['quantity'], $override, $discount);

            $priced[] = ['product' => $product] + $q;
            $totals['discount_total'] = Decimal::add($totals['discount_total'], $q['discount']);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $q['tax']);
            $totals['net_total'] = Decimal::add($totals['net_total'], $q['net']);
            $totals['grand_total'] = Decimal::add($totals['grand_total'], $q['total']);
        }

        return ['lines' => $priced] + $totals + [
            // Pre-discount, pre-tax value of the goods.
            'subtotal' => Decimal::add($totals['net_total'], $totals['discount_total']),
        ];
    }
}
