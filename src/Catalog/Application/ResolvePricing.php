<?php

declare(strict_types=1);

namespace Catalog\Application;

use Catalog\Infrastructure\Persistence\Eloquent\BranchPrice;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\PromotionRule;
use Illuminate\Support\Collection;
use Shared\Domain\Support\Decimal;

/**
 * Works out, for one branch at one moment, what each product sells for and
 * which promotion (if any) applies:
 *
 *  - price: the branch-wise price when one is set, else the product's sale price
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

    public function unitPrice(Product $product): string
    {
        return $this->branchPrices[$product->id] ?? (string) $product->sale_price;
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
