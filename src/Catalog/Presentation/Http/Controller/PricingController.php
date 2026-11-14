<?php

declare(strict_types=1);

namespace Catalog\Presentation\Http\Controller;

use Catalog\Infrastructure\Persistence\Eloquent\BranchPrice;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\PromotionItem;
use Catalog\Infrastructure\Persistence\Eloquent\PromotionRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Shared\Domain\Contract\AuditTrail;
use Shared\Infrastructure\Validation\TenantRule;

/** Branch-wise prices, promotions (deals) and coupons. */
final class PricingController
{
    // ── Branch prices ────────────────────────────────────────────

    public function branchPrices(): JsonResponse
    {
        return new JsonResponse(['data' => BranchPrice::query()->orderBy('branch_id')->limit(5000)->get()]);
    }

    public function storeBranchPrice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'uuid', TenantRule::exists('branches')],
            'product_id' => ['required', 'uuid', TenantRule::exists('products')],
            'price' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ]);

        $price = BranchPrice::query()->updateOrCreate(
            ['branch_id' => $data['branch_id'], 'product_id' => $data['product_id']],
            ['price' => $data['price']],
        );

        return new JsonResponse(['data' => $price], 201);
    }

    public function updateBranchPrice(Request $request, string $priceId): JsonResponse
    {
        $price = BranchPrice::findOrFail($priceId);
        $price->update($request->validate(['price' => ['required', 'numeric', 'min:0', 'decimal:0,4']]));

        return new JsonResponse(['data' => $price]);
    }

    public function destroyBranchPrice(string $priceId): JsonResponse
    {
        BranchPrice::findOrFail($priceId)->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    // ── Promotions ───────────────────────────────────────────────

    public function promotions(Request $request): JsonResponse
    {
        $q = $request->validate(['company_id' => ['required', 'uuid', TenantRule::exists('companies')]]);

        $rows = PromotionRule::query()->with('items')->where('company_id', $q['company_id'])->orderByDesc('start_at')->get()
            ->map($this->presentPromotion(...));

        return new JsonResponse(['data' => $rows]);
    }

    public function storePromotion(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'type' => ['required', 'in:percentage,fixed'],
        ] + $this->promotionRules(required: true));

        $this->assertValue($data['type'], (string) $data['value']);

        $promotion = DB::transaction(function () use ($data, $audit): PromotionRule {
            $promotion = PromotionRule::query()->create([
                'company_id' => $data['company_id'],
                'name' => $data['name'],
                'type' => $data['type'],
                'value' => $data['value'],
                'start_at' => $data['start_at'],
                'end_at' => $data['end_at'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'requires_coupon' => $data['requires_coupon'] ?? false,
                'status' => 'active',
            ]);

            $this->syncItems($promotion, $data);
            $audit->record('catalog.promotion.created', 'promotion', $promotion->id, null, ['name' => $promotion->name, 'type' => $promotion->type, 'value' => (string) $promotion->value]);

            return $promotion;
        });

        return new JsonResponse(['data' => $this->presentPromotion($promotion->load('items'))], 201);
    }

    public function updatePromotion(Request $request, string $promotionId, AuditTrail $audit): JsonResponse
    {
        $promotion = PromotionRule::findOrFail($promotionId);

        $data = $request->validate(['status' => ['sometimes', 'in:active,inactive']] + $this->promotionRules(required: false));

        if (isset($data['value'])) {
            $this->assertValue($promotion->type, (string) $data['value']);
        }

        DB::transaction(function () use ($promotion, $data, $audit): void {
            $before = $promotion->only(['name', 'value', 'status', 'end_at']);
            $promotion->update(array_intersect_key($data, array_flip(['name', 'value', 'start_at', 'end_at', 'branch_id', 'requires_coupon', 'status'])));
            $this->syncItems($promotion, $data);
            $audit->record('catalog.promotion.updated', 'promotion', $promotion->id, $before, $promotion->only(['name', 'value', 'status', 'end_at']));
        });

        return new JsonResponse(['data' => $this->presentPromotion($promotion->fresh('items'))]);
    }

    // ── Coupons ──────────────────────────────────────────────────

    public function coupons(Request $request): JsonResponse
    {
        $q = $request->validate(['company_id' => ['required', 'uuid', TenantRule::exists('companies')]]);

        return new JsonResponse(['data' => Coupon::query()->where('company_id', $q['company_id'])->orderByDesc('valid_from')->get()]);
    }

    public function storeCoupon(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'company_id' => ['required', 'uuid', TenantRule::exists('companies')],
            'code' => ['required', 'string', 'max:50', 'alpha_dash', TenantRule::unique('coupons', 'code')],
            'promotion_rule_id' => ['required', 'uuid', Rule::exists('promotion_rules', 'id')->where('company_id', $request->input('company_id'))],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['nullable', 'date', 'after:valid_from'],
        ]);

        return new JsonResponse(['data' => Coupon::query()->create($data + ['status' => 'active'])], 201);
    }

    public function updateCoupon(Request $request, string $couponId): JsonResponse
    {
        $coupon = Coupon::findOrFail($couponId);
        $coupon->update($request->validate([
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'valid_to' => ['nullable', 'date'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return new JsonResponse(['data' => $coupon]);
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function promotionRules(bool $required): array
    {
        $need = $required ? 'required' : 'sometimes';

        return [
            'name' => [$need, 'string', 'max:150'],
            'value' => [$need, 'numeric', 'gt:0', 'decimal:0,4'],
            'start_at' => [$need, 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'requires_coupon' => ['sometimes', 'boolean'],
            'product_ids' => ['sometimes', 'array', 'max:2000'],
            'product_ids.*' => ['uuid', TenantRule::exists('products')],
            'category_ids' => ['sometimes', 'array', 'max:500'],
            'category_ids.*' => ['uuid', TenantRule::exists('product_categories')],
        ];
    }

    private function assertValue(string $type, string $value): void
    {
        if ($type === 'percentage' && bccomp($value, '100', 4) > 0) {
            throw new \DomainException('A percentage discount cannot be more than 100.');
        }
    }

    /** @param  array<string, mixed>  $data */
    private function syncItems(PromotionRule $promotion, array $data): void
    {
        if (! array_key_exists('product_ids', $data) && ! array_key_exists('category_ids', $data)) {
            return;
        }

        PromotionItem::query()->where('promotion_rule_id', $promotion->id)->delete();

        foreach ($data['product_ids'] ?? [] as $productId) {
            PromotionItem::query()->create(['promotion_rule_id' => $promotion->id, 'product_id' => $productId]);
        }
        foreach ($data['category_ids'] ?? [] as $categoryId) {
            PromotionItem::query()->create(['promotion_rule_id' => $promotion->id, 'category_id' => $categoryId]);
        }
    }

    /** @return array<string, mixed> */
    private function presentPromotion(PromotionRule $promotion): array
    {
        return $promotion->only(['id', 'name', 'type', 'value', 'start_at', 'end_at', 'branch_id', 'requires_coupon', 'status']) + [
            'product_ids' => $promotion->items->pluck('product_id')->filter()->values(),
            'category_ids' => $promotion->items->pluck('category_id')->filter()->values(),
        ];
    }
}
