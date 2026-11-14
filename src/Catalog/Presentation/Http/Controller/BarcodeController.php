<?php

declare(strict_types=1);

namespace Catalog\Presentation\Http\Controller;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Catalog\Infrastructure\Persistence\Eloquent\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BarcodeController
{
    /** Exact match on barcode, then SKU — the POS scan path. */
    public function lookup(Request $request): JsonResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:128']])['code']);

        $product = Product::query()
            ->with(['baseUnit:id,name,abbreviation', 'taxCode:id,code,rate,type'])
            ->where('status', 'active')
            ->where(fn ($w) => $w
                ->whereHas('barcodes', fn ($b) => $b->where('barcode', $code))
                ->orWhere('sku', strtoupper($code)))
            ->first();

        $unitId = null;

        if ($product === null) {
            // A pack (carton, dozen) can carry its own barcode.
            $unit = ProductUnit::query()->where('barcode', $code)->first();
            $product = $unit === null ? null : Product::query()
                ->with(['baseUnit:id,name,abbreviation', 'taxCode:id,code,rate,type'])
                ->where('status', 'active')->find($unit->product_id);
            $unitId = $product === null ? null : $unit->id;
        }

        abort_if($product === null, 404, 'No active product matches that code.');

        return new JsonResponse(['data' => $product->load('units')->setAttribute('scanned_unit_id', $unitId)]);
    }
}
