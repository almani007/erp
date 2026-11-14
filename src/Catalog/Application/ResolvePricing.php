<?php

declare(strict_types=1);

namespace Catalog\Application;

use Catalog\Infrastructure\Persistence\Eloquent\BranchPrice;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\MarketRate;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductUnit;
use Catalog\Infrastructure\Persistence\Eloquent\PromotionRule;
use Illuminate\Support\Collection;
use Shared\Domain\Support\Decimal;

/**
 * Works out, for one branch at one moment, what each product sells for and
 * which promotion (if any) applies:
 *
 *  - price: market-rate price for rate-priced goods (gold, silver, steel by weight);
 *    otherwise the wholesale price for a wholesale customer buying the minimum
 *    quantity; otherwise the branch-wise price; otherwise the default sale price.
 *    Packs (carton, dozen) use their own price or factor × the unit price.
 *  - promotion: the single best active deal for the line — deals do not stack.
 *    A deal marked "requires coupon" only counts when its coupon code was given.
 */
final class ResolvePricing
{
    /** @var array<string, string> product id → branch price */
    private array $branchPrices = [];

    /** @var Collection<int, PromotionRule> */
    private Collection $promotions;

    private ?Coupon $coupon = null;

    /** @var array<string, string> market rate code → rate */
    private array $rates = [];

    public function __construct()
    {
        $this->promotions = collect();
    }

    /**
     * Load prices and deals for a cart. Call once before {@see unitPrice()} / {@see bestPromotion()}.
     *
     * @param  list<string>  $productIds
     *
     * @throws \DomainException when the coupon code is unknown, expired or used up
     */
    public function load(?string $branchId, ?string $companyId, array $productIds, ?string $couponCode): void
    {
        $this->branchPrices = $branchId === null ? [] : BranchPrice::query()
            ->where('branch_id', $branchId)->whereIn('product_id', $productIds)
            ->pluck('price', 'product_id')->map(static fn ($p): string => (string) $p)->all();

        $this->rates = MarketRate::query()->pluck('rate', 'code')->map(static fn ($r): string => (string) $r)->all();

        $this->coupon = $couponCode === null || $couponCode === '' ? null : $this->findCoupon($couponCode, $companyId);

        $this->promotions = $companyId === null ? collect() : PromotionRule::query()
            ->with('items')
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('start_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId, fn ($b) => $b->orWhere('branch_id', $branchId)))
            ->where(function ($q): void {
                $q->where('requires_coupon', false);
                if ($this->coupon !== null) {
                    $q->orWhere('id', $this->coupon->promotion_rule_id);
                }
            })
            ->get();
    }

    public function coupon(): ?Coupon
    {
        return $this->coupon;
    }

    /**
     * List price for one selling unit of the line.
     *
     * @param  string  $tier  the customer's price tier: retail | wholesale
     * @param  string  $baseQuantity  quantity in base units (decides whether the wholesale minimum is met)
     * @param  ProductUnit|null  $unit  the pack being sold, or null for the base unit
     */
    public function unitPrice(Product $product, string $tier = 'retail', string $baseQuantity = '1', ?ProductUnit $unit = null): string
    {
        $wholesale = $tier === 'wholesale';

        if ($product->pricing_mode === 'rate') {
            $base = $this->ratePrice($product);
        } elseif ($wholesale && Decimal::isPositive((string) $product->wholesale_price)
            && Decimal::cmp($baseQuantity, (string) $product->wholesale_min_qty) >= 0) {
            $base = (string) $product->wholesale_price;
        } else {
            $base = $this->branchPrices[$product->id] ?? (string) $product->sale_price;
        }

        if ($unit === null) {
            return $base;
        }

        // A pack has its own price when one is set; otherwise it is simply factor × the unit price.
        $packPrice = $wholesale && $unit->wholesale_price !== null ? $unit->wholesale_price : $unit->price;

        return $packPrice !== null ? (string) $packPrice : Decimal::mul($base, (string) $unit->factor);
    }

    /**
     * Rate-based price: weight × today's rate, plus wastage, plus making charge.
     * A product with no weight is sold by weight itself (the quantity is the
     * weight), so its unit price is the rate plus the making charge per unit.
     */
    private function ratePrice(Product $product): string
    {
        $rate = $this->rates[(string) $product->rate_code]
            ?? throw new \DomainException("No market rate is set for '{$product->rate_code}' ({$product->name}). Enter today's rate under Pricing & deals → Market rates.");

        $weight = $product->weight !== null ? (string) $product->weight : '1';
        $metal = Decimal::mul($weight, $rate, 6);
        $withWastage = Decimal::mul($metal, Decimal::add('1', Decimal::div((string) $product->wastage_percent, '100', 8), 8), 6);

        return Decimal::round(Decimal::add($withWastage, (string) $product->making_charge, 6), 2);
    }

    /**
     * @param  string  $gross  unit price × quantity
     * @return array{discount: string, name: string|null}
     */
    public function bestPromotion(Product $product, string $unitPrice, string $quantity, string $gross): array
    {
        $best = ['discount' => '0', 'name' => null];

        foreach ($this->promotions as $promotion) {
            if (! $this->covers($promotion, $product)) {
                continue;
            }

            $discount = match ($promotion->type) {
                'percentage' => Decimal::mul($gross, Decimal::div((string) $promotion->value, '100', 8), 2),
                'fixed' => Decimal::mul(
                    Decimal::cmp((string) $promotion->value, $unitPrice) > 0 ? $unitPrice : (string) $promotion->value,
                    $quantity,
                    2,
                ),
                default => '0',
            };

            if (Decimal::cmp($discount, $gross) > 0) {
                $discount = $gross;
            }

            if (Decimal::cmp($discount, $best['discount']) > 0) {
                $best = ['discount' => $discount, 'name' => $promotion->name];
            }
        }

        return $best;
    }

    /** A deal with no items covers everything; otherwise the product or its category must be listed. */
    private function covers(PromotionRule $promotion, Product $product): bool
    {
        if ($promotion->items->isEmpty()) {
            return true;
        }

        return $promotion->items->contains(
            static fn ($item): bool => $item->product_id === $product->id
                || ($item->category_id !== null && $item->category_id === $product->category_id),
        );
    }

    private function findCoupon(string $code, ?string $companyId): Coupon
    {
        $coupon = Coupon::query()
            ->where('code', strtoupper(trim($code)))
            ->where('status', 'active')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()))
            ->first();

        if ($coupon === null || ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses)) {
            throw new \DomainException('That coupon code is not valid, has expired or has been used up.');
        }

        return $coupon;
    }
}
