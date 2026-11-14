<?php

declare(strict_types=1);

namespace Sales\Application;

use Catalog\Application\QuotePrice;
use Catalog\Application\ResolvePricing;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Shared\Domain\Exception\InsufficientPermission;
use Shared\Domain\Support\Decimal;

/**
 * Prices a cart on the server. The POS preview and the committed sale both
 * go through here, so what the cashier sees is what gets posted.
 *
 * Per line: branch price (or default price) → best promotion → any manual
 * discount on top → tax.
 */
final class PriceCart
{
    public function __construct(
        private readonly QuotePrice $quote,
        private readonly ResolvePricing $pricing,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string|int|float, unit_price?: string|int|float|null, discount?: string|int|float|null}>  $lines
     * @param  bool  $mayOverride  caller holds sales.price.override
     * @param  string|null  $branchId  branch the sale happens in (branch prices and branch-only deals)
     * @return array{
     *     lines: list<array<string, mixed>>, coupon: Coupon|null,
     *     subtotal: string, discount_total: string, tax_total: string, net_total: string, grand_total: string
     * }
     */
    public function price(array $lines, bool $mayOverride, ?string $branchId = null, ?string $couponCode = null): array
    {
        $products = Product::query()
            ->with('taxCode')
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        $companyId = $branchId === null ? null : Branch::query()->whereKey($branchId)->value('company_id');
        $this->pricing->load($branchId, $companyId, $products->keys()->all(), $couponCode);

        $priced = [];
        $totals = ['discount_total' => '0', 'tax_total' => '0', 'net_total' => '0', 'grand_total' => '0'];

        foreach ($lines as $line) {
            $product = $products->get($line['product_id'])
                ?? throw new \DomainException('A product in the cart no longer exists.');

            if (! $product->status->isSellable()) {
                throw new \DomainException("{$product->name} is not available for sale.");
            }

            $listPrice = $this->pricing->unitPrice($product);
            $override = isset($line['unit_price']) ? (string) $line['unit_price'] : null;
            $manualDiscount = Decimal::round((string) ($line['discount'] ?? '0'), 2);
            $quantity = (string) $line['quantity'];

            $changesPrice = ($override !== null && Decimal::cmp($override, $listPrice) !== 0) || Decimal::isPositive($manualDiscount);
            if ($changesPrice && ! $mayOverride) {
                throw InsufficientPermission::for('sales.price.override');
            }

            $unitPrice = $override ?? $listPrice;
            $gross = Decimal::mul($unitPrice, $quantity, 2);

            // A deal applies to the list price; a manually keyed price is already the cashier's decision.
            $promotion = $override !== null && Decimal::cmp($override, $listPrice) !== 0
                ? ['discount' => '0', 'name' => null]
                : $this->pricing->bestPromotion($product, $unitPrice, $quantity, $gross);

            $discount = Decimal::add($promotion['discount'], $manualDiscount, 2);

            $q = $this->quote->line($product, $quantity, $unitPrice, $discount);

            $priced[] = ['product' => $product, 'list_price' => $listPrice, 'promotion_discount' => $promotion['discount'], 'promotion_name' => $promotion['name']] + $q;
            $totals['discount_total'] = Decimal::add($totals['discount_total'], $q['discount']);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $q['tax']);
            $totals['net_total'] = Decimal::add($totals['net_total'], $q['net']);
            $totals['grand_total'] = Decimal::add($totals['grand_total'], $q['total']);
        }

        return ['lines' => $priced, 'coupon' => $this->pricing->coupon()] + $totals + [
            // Pre-discount, pre-tax value of the goods.
            'subtotal' => Decimal::add($totals['net_total'], $totals['discount_total']),
        ];
    }
}
