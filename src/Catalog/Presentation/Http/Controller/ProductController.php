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
        'taxCode:id,code,rate,type', 'barcodes:id,product_id,barcode,is_primary',
    ];

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:active,inactive,discontinued'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
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
            'barcodes' => ['sometimes', 'array', 'max:20'],
            'barcodes.*' => ['string', 'max:128'],
        ];
    }
}
