<?php

declare(strict_types=1);

namespace Catalog\Presentation\Http\Controller;

use Catalog\Infrastructure\Persistence\Eloquent\Product;
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

        abort_if($product === null, 404, 'No active product matches that code.');

        return new JsonResponse(['data' => $product]);
    }
}
