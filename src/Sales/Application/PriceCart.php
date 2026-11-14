<?php

declare(strict_types=1);

namespace Sales\Application;

use Catalog\Application\QuotePrice;
use Catalog\Application\ResolvePricing;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductUnit;
use Organization\Infrastructure\Persistence\Eloquent\Branch;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Shared\Domain\Exception\InsufficientPermission;
use Shared\Domain\Support\Decimal;

/**
 * Prices a cart on the server. The POS preview and the committed sale both
 * go through here, so what the cashier sees is what gets posted.
 *
 * Per line: list price (market rate, wholesale, branch or default; per unit
 * or per pack) → best promotion → any manual discount on top → tax.
 */
final class PriceCart
{
    public function __construct(
        private readonly QuotePrice $quote,
        private readonly ResolvePricing $pricing,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string|int|float, unit_id?: string|null, unit_price?: string|int|float|null, discount?: string|int|float|null}>  $lines
     * @param  bool  $mayOverride  caller holds sales.price.override
     * @param  string|null  $branchId  branch the sale happens in (branch prices and branch-only deals)
     * @param  string|null  $customerId  decides the price tier (retail / wholesale) unless $priceTier is given
     * @param  string|null  $priceTier  retail | wholesale, chosen at the till
     * @return array{
     *     lines: list<array<string, mixed>>, coupon: Coupon|null, price_tier: string,
     *     subtotal: string, discount_total: string, tax_total: string, net_total: string, grand_total: string
     * }
     */
    public function price(array $lines, bool $mayOverride, ?string $branchId = null, ?string $couponCode = null, ?string $customerId = null, ?string $priceTier = null): array
    {
        $products = Product::query()
            ->with('taxCode')
            ->whereIn('id', array_column($lines, 'product_id'))
            ->get()
            ->keyBy('id');

        $units = ProductUnit::query()->whereIn('id', array_filter(array_column($lines, 'unit_id')))->get()->keyBy('id');
        // The till can ask for a tier outright (a walk-in trade buyer); otherwise the customer's own tier applies.
        $tier = $priceTier ?? ($customerId === null ? 'retail' : (string) (Customer::query()->whereKey($customerId)->value('price_tier') ?? 'retail'));

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

            $unit = null;
            if (! empty($line['unit_id'])) {
                $unit = $units->get($line['unit_id']);
                if ($unit === null || $unit->product_id !== $product->id) {
                    throw new \DomainException("That pack is not defined for {$product->name}.");
                }
            }

            $quantity = (string) $line['quantity'];
            $factor = $unit === null ? '1' : (string) $unit->factor;
            $baseQuantity = Decimal::mul($quantity, $factor, Decimal::QTY_SCALE);

            $listPrice = $this->pricing->unitPrice($product, $tier, $baseQuantity, $unit);
            $override = isset($line['unit_price']) ? (string) $line['unit_price'] : null;
            $manualDiscount = Decimal::round((string) ($line['discount'] ?? '0'), 2);

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

            $priced[] = [
                'product' => $product,
                'list_price' => $listPrice,
                'promotion_discount' => $promotion['discount'],
                'promotion_name' => $promotion['name'],
                'unit_id' => $unit?->id,
                'unit_name' => $unit?->name,
                'unit_factor' => $factor,
                'base_quantity' => $baseQuantity,
            ] + $q;

            $totals['discount_total'] = Decimal::add($totals['discount_total'], $q['discount']);
            $totals['tax_total'] = Decimal::add($totals['tax_total'], $q['tax']);
            $totals['net_total'] = Decimal::add($totals['net_total'], $q['net']);
            $totals['grand_total'] = Decimal::add($totals['grand_total'], $q['total']);
        }

        return ['lines' => $priced, 'coupon' => $this->pricing->coupon(), 'price_tier' => $tier] + $totals + [
            // Pre-discount, pre-tax value of the goods.
            'subtotal' => Decimal::add($totals['net_total'], $totals['discount_total']),
        ];
    }
}
