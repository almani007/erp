<?php

declare(strict_types=1);

namespace Catalog\Presentation\Http\Controller;

use Catalog\Application\SaveProduct;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Infrastructure\Validation\TenantRule;

final class ProductController
{
    private const array RELATIONS = [
        'category:id,name', 'brand:id,name', 'baseUnit:id,name,abbreviation',
        'taxCode:id,code,rate,type', 'barcodes:id,product_id,barcode,is_primary', 'units',
    ];

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive,discontinued'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'parent_id' => ['nullable', 'uuid'],
        ]);

        $products = Product::query()
            ->with(self::RELATIONS)
            ->when($q['q'] ?? null, function ($b, string $term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $b->where(fn ($w) => $w
                    ->where('name', 'ilike', $like)
                    ->orWhere('sku', 'ilike', $like)
                    ->orWhereHas('barcodes', fn ($bc) => $bc->where('barcode', $term)));
            })
            ->when($q['category_id'] ?? null, fn ($b, $id) => $b->where('category_id', $id))
            ->when($q['status'] ?? null, fn ($b, $s) => $b->where('status', $s))
            ->when($q['parent_id'] ?? null, fn ($b, $id) => $b->where('parent_id', $id))
            ->withCount('children')
            ->orderBy('name')
            ->limit($q['limit'] ?? 500)
            ->get();

        return new JsonResponse(['data' => $products]);
    }

    public function show(string $productId): JsonResponse
    {
        return new JsonResponse(['data' => Product::with(self::RELATIONS)->findOrFail($productId)]);
    }

    public function store(Request $request, SaveProduct $action): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80', TenantRule::unique('products', 'sku')],
            'name' => ['required', 'string', 'max:200'],
            'product_type' => ['required', 'in:goods,service,composite,non_stock'],
            'base_unit_id' => ['required', 'uuid', TenantRule::exists('units')],
        ] + $this->sharedRules());

        return new JsonResponse(['data' => $action->execute($data)], 201);
    }

    public function update(Request $request, string $productId, SaveProduct $action): JsonResponse
    {
        $product = Product::findOrFail($productId);

        // SKU, type and base unit are fixed once stock or sales may reference them.
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'in:active,inactive,discontinued'],
        ] + $this->sharedRules());

        return new JsonResponse(['data' => $action->execute($data, $product)]);
    }

    /** @return array<string, mixed> */
    private function sharedRules(): array
    {
        return [
            'category_id' => ['nullable', 'uuid', TenantRule::exists('product_categories')],
            'brand_id' => ['nullable', 'uuid', TenantRule::exists('product_brands')],
            'tax_code_id' => ['nullable', 'uuid', TenantRule::exists('tax_codes')],
            'sale_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'cost_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0'],
            'track_batch' => ['sometimes', 'boolean'],
            'track_serial' => ['sometimes', 'boolean'],
            'track_expiry' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'wholesale_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999'],
            'wholesale_min_qty' => ['sometimes', 'numeric', 'min:0'],
            'pricing_mode' => ['sometimes', 'in:fixed,rate'],
            'rate_code' => ['nullable', 'string', 'max:30', TenantRule::exists('market_rates', 'code')],
            'weight' => ['nullable', 'numeric', 'gt:0'],
            'making_charge' => ['sometimes', 'numeric', 'min:0'],
            'wastage_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'purity' => ['nullable', 'string', 'max:30'],
            'warranty_months' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'kitchen_station' => ['nullable', 'string', 'max:40'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'hs_code' => ['nullable', 'string', 'max:20'],
            ...Product::customFieldRules(),
            'barcodes' => ['sometimes', 'array', 'max:20'],
            'barcodes.*' => ['string', 'max:128'],
        ];
    }
}
