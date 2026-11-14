<?php

declare(strict_types=1);

namespace Catalog\Application;

use Catalog\Domain\Exception\DuplicateBarcode;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductBarcode;
use Illuminate\Support\Facades\DB;

final class SaveProduct
{
    private const array FIELDS = [
        'sku', 'name', 'product_type', 'base_unit_id', 'category_id', 'brand_id', 'tax_code_id',
        'track_batch', 'track_serial', 'track_expiry', 'sale_price', 'cost_price', 'reorder_level',
        'status', 'description', 'wholesale_price', 'wholesale_min_qty', 'pricing_mode', 'rate_code', 'weight',
        'making_charge', 'wastage_percent', 'purity', 'warranty_months', 'kitchen_station', 'duration_minutes', 'hs_code',
    ];

    /**
     * Create ($product === null) or update a product together with its barcodes.
     *
     * @param  array<string, mixed>  $data  validated input; `barcodes` (list of strings) is optional
     */
    public function execute(array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($data, $product): Product {
            $product ??= new Product(['status' => 'active']);

            $attributes = array_intersect_key($data, array_flip(self::FIELDS));
            if (isset($attributes['sku'])) {
                $attributes['sku'] = strtoupper(trim((string) $attributes['sku']));
            }

            $product->fill($attributes);
            $product->mergeCustom($data['custom'] ?? null);

            if ($product->pricing_mode === 'rate' && empty($product->rate_code)) {
                throw new \DomainException('Choose the market rate this product is priced from.');
            }

            $product->save();

            if (array_key_exists('barcodes', $data)) {
                $this->syncBarcodes($product, array_values(array_unique(array_filter(array_map('trim', $data['barcodes'])))));
            }

            return $product->load(['category:id,name', 'brand:id,name', 'baseUnit:id,name,abbreviation', 'taxCode:id,code,rate,type', 'barcodes:id,product_id,barcode,is_primary', 'units']);
        });
    }

    /** @param  list<string>  $barcodes */
    private function syncBarcodes(Product $product, array $barcodes): void
    {
        foreach ($barcodes as $barcode) {
            $taken = ProductBarcode::query()->where('barcode', $barcode)->where('product_id', '!=', $product->id)->exists();

            if ($taken) {
                throw DuplicateBarcode::forBarcode($barcode);
            }
        }

        ProductBarcode::query()->where('product_id', $product->id)->whereNotIn('barcode', $barcodes)->delete();

        $existing = ProductBarcode::query()->where('product_id', $product->id)->pluck('barcode')->all();

        foreach ($barcodes as $index => $barcode) {
            if (! in_array($barcode, $existing, true)) {
                ProductBarcode::query()->create([
                    'product_id' => $product->id,
                    'barcode' => $barcode,
                    'unit_id' => $product->base_unit_id,
                    'is_primary' => $index === 0,
                ]);
            }
        }
    }
}
