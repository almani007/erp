<?php

declare(strict_types=1);

namespace Catalog\Presentation\Http\Controller;

use Catalog\Infrastructure\Persistence\Eloquent\MarketRate;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Shared\Domain\Contract\AuditTrail;
use Shared\Infrastructure\Validation\TenantRule;

/**
 * Trade-specific product features: packs (wholesale), size/colour variants
 * (garments, footwear) and market rates (gold, silver, steel by weight).
 */
final class TradeController
{
    // ── Packs ────────────────────────────────────────────────────

    public function units(string $productId): JsonResponse
    {
        return new JsonResponse(['data' => Product::findOrFail($productId)->units]);
    }

    public function storeUnit(Request $request, string $productId): JsonResponse
    {
        $product = Product::findOrFail($productId);

        $data = $request->validate($this->unitRules() + [
            'name' => ['required', 'string', 'max:50', Rule::unique('product_units', 'name')->where('product_id', $product->id)],
            'factor' => ['required', 'numeric', 'gt:0', 'decimal:0,6'],
        ]);

        return new JsonResponse(['data' => ProductUnit::query()->create($data + ['product_id' => $product->id])], 201);
    }

    public function updateUnit(Request $request, string $productId, string $unitId): JsonResponse
    {
        $unit = ProductUnit::query()->where('product_id', $productId)->findOrFail($unitId);

        // The factor is fixed: past sales in this pack were converted to stock with it.
        $unit->update($request->validate($this->unitRules()));

        return new JsonResponse(['data' => $unit]);
    }

    public function destroyUnit(string $productId, string $unitId): JsonResponse
    {
        ProductUnit::query()->where('product_id', $productId)->findOrFail($unitId)->delete();

        return new JsonResponse(['data' => ['deleted' => true]]);
    }

    // ── Variants ─────────────────────────────────────────────────

    /**
     * Create one product per combination of the given options, e.g.
     * {"Size": ["S","M","L"], "Colour": ["Red","Blue"]} → 6 variants.
     * Each variant copies the style's unit, category, tax and prices and
     * gets its own SKU and stock. The style itself is switched to inactive
     * so only real, countable variants can be sold. Existing SKUs are skipped.
     */
    public function generateVariants(Request $request, string $productId, AuditTrail $audit): JsonResponse
    {
        $style = Product::findOrFail($productId);

        $data = $request->validate([
            'options' => ['required', 'array', 'min:1', 'max:3'],
            'options.*' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.*' => ['required', 'string', 'max:30'],
        ]);

        if ($style->parent_id !== null) {
            throw new \DomainException('This product is already a variant; generate variants from its style.');
        }

        $combinations = [[]];
        foreach ($data['options'] as $option => $values) {
            $next = [];
            foreach ($combinations as $combination) {
                foreach (array_unique(array_map('trim', $values)) as $value) {
                    $next[] = $combination + [(string) $option => $value];
                }
            }
            $combinations = $next;
        }

        if (count($combinations) > 300) {
            throw new \DomainException('That would create '.count($combinations).' variants; the limit is 300 at a time.');
        }

        $created = DB::transaction(function () use ($style, $combinations, $audit): int {
            $created = 0;
            $copied = $style->only([
                'product_type', 'base_unit_id', 'category_id', 'brand_id', 'tax_code_id', 'sale_price', 'cost_price',
                'reorder_level', 'wholesale_price', 'wholesale_min_qty', 'pricing_mode', 'rate_code', 'making_charge',
                'wastage_percent', 'purity', 'description',
            ]);
            $copied['product_type'] = $style->product_type->value;

            foreach ($combinations as $attributes) {
                $sku = strtoupper($style->sku.'-'.implode('-', array_map(static fn (string $v): string => Str::slug($v), $attributes)));

                if (Product::query()->where('sku', $sku)->exists()) {
                    continue;
                }

                Product::query()->create($copied + [
                    'sku' => $sku,
                    'name' => $style->name.' — '.implode(' / ', $attributes),
                    'parent_id' => $style->id,
                    'variant_attributes' => $attributes,
                    'status' => 'active',
                ]);
                $created++;
            }

            if ($created > 0) {
                $style->update(['status' => 'inactive']);
                $audit->record('catalog.product.variants_generated', 'product', $style->id, null, ['created' => $created]);
            }

            return $created;
        });

        return new JsonResponse(['data' => ['created' => $created, 'skipped' => count($combinations) - $created]], 201);
    }

    // ── Market rates ─────────────────────────────────────────────

    public function rates(): JsonResponse
    {
        return new JsonResponse(['data' => MarketRate::query()->orderBy('code')->get()]);
    }

    public function storeRate(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9-]+$/', TenantRule::unique('market_rates', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'per_unit' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
        ]);

        return new JsonResponse(['data' => MarketRate::query()->create($data)], 201);
    }

    /** Today's rate. Every product priced from this code changes price immediately; the change is audited. */
    public function updateRate(Request $request, string $rateId, AuditTrail $audit): JsonResponse
    {
        $rate = MarketRate::findOrFail($rateId);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'per_unit' => ['sometimes', 'string', 'max:20'],
            'rate' => ['sometimes', 'numeric', 'gt:0', 'decimal:0,4'],
        ]);

        DB::transaction(function () use ($rate, $data, $audit): void {
            $before = (string) $rate->rate;
            $rate->update($data);

            if (isset($data['rate']) && bccomp($before, (string) $data['rate'], 4) !== 0) {
                $audit->record('catalog.market_rate.changed', 'market_rate', $rate->id, ['rate' => $before], ['rate' => (string) $data['rate'], 'code' => $rate->code]);
            }
        });

        return new JsonResponse(['data' => $rate]);
    }

    /** @return array<string, mixed> */
    private function unitRules(): array
    {
        return [
            'price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
            'barcode' => ['nullable', 'string', 'max:128'],
        ];
    }
}
