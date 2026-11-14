<?php

declare(strict_types=1);

namespace Catalog\Presentation\Http\Controller;

use Catalog\Infrastructure\Persistence\Eloquent\ProductBrand;
use Catalog\Infrastructure\Persistence\Eloquent\ProductCategory;
use Catalog\Infrastructure\Persistence\Eloquent\TaxCode;
use Catalog\Infrastructure\Persistence\Eloquent\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Shared\Infrastructure\Validation\TenantRule;

/**
 * Small reference lists the product form depends on: categories, brands,
 * units and tax codes.
 */
final class MasterDataController
{
    // ── Categories ───────────────────────────────────────────────

    public function categories(): JsonResponse
    {
        return new JsonResponse(['data' => ProductCategory::query()->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $request->merge(['slug' => Str::slug((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', TenantRule::unique('product_categories', 'slug')],
            'parent_id' => ['nullable', 'uuid', TenantRule::exists('product_categories')],
            'sort_order' => ['sometimes', 'integer'],
        ], ['slug.unique' => 'A category with this name already exists.']);

        return new JsonResponse(['data' => ProductCategory::query()->create($data + ['status' => 'active'])], 201);
    }

    public function updateCategory(Request $request, string $categoryId): JsonResponse
    {
        $category = ProductCategory::findOrFail($categoryId);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'parent_id' => ['nullable', 'uuid', TenantRule::exists('product_categories')],
            'sort_order' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        if (($data['parent_id'] ?? null) === $category->id) {
            throw new \DomainException('A category cannot be its own parent.');
        }

        $category->update($data);

        return new JsonResponse(['data' => $category->fresh()]);
    }

    // ── Brands ───────────────────────────────────────────────────

    public function brands(): JsonResponse
    {
        return new JsonResponse(['data' => ProductBrand::query()->orderBy('name')->get()]);
    }

    public function storeBrand(Request $request): JsonResponse
    {
        $request->merge(['slug' => Str::slug((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', TenantRule::unique('product_brands', 'slug')],
        ], ['slug.unique' => 'A brand with this name already exists.']);

        return new JsonResponse(['data' => ProductBrand::query()->create($data + ['status' => 'active'])], 201);
    }

    public function updateBrand(Request $request, string $brandId): JsonResponse
    {
        $brand = ProductBrand::findOrFail($brandId);
        $brand->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return new JsonResponse(['data' => $brand->fresh()]);
    }

    // ── Units ────────────────────────────────────────────────────

    public function units(): JsonResponse
    {
        return new JsonResponse(['data' => Unit::query()->orderBy('name')->get()]);
    }

    public function storeUnit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'abbreviation' => ['required', 'string', 'max:20', TenantRule::unique('units', 'abbreviation')],
        ]);

        return new JsonResponse(['data' => Unit::query()->create($data + ['is_base' => true])], 201);
    }

    // ── Tax codes ────────────────────────────────────────────────

    public function taxCodes(): JsonResponse
    {
        return new JsonResponse(['data' => TaxCode::query()->orderBy('code')->get()]);
    }

    public function storeTaxCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', TenantRule::unique('tax_codes', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'type' => ['required', 'in:inclusive,exclusive'],
        ]);

        return new JsonResponse(['data' => TaxCode::query()->create($data + ['status' => 'active'])], 201);
    }

    public function updateTaxCode(Request $request, string $taxCodeId): JsonResponse
    {
        $taxCode = TaxCode::findOrFail($taxCodeId);

        // Rate and type are not editable: posted sales keep their own tax
        // snapshot, but changing a live code silently re-prices open carts.
        // Create a new code for a new rate and retire the old one.
        $taxCode->update($request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]));

        return new JsonResponse(['data' => $taxCode->fresh()]);
    }
}
