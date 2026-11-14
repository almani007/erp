<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;
use Production\Infrastructure\Persistence\Eloquent\ProductionWastage;

final class RecordWastage
{
    /**
     * @param array{
     *     production_order_id: string,
     *     product_id: string,
     *     qty: string,
     *     unit_id: string,
     *     reason: string,
     *     unit_cost: string,
     *     lot_id?: string|null,
     *     recorded_by: string,
     * } $data
     */
    public function execute(array $data): ProductionWastage
    {
        return DB::transaction(function () use ($data): ProductionWastage {
            $order = ProductionOrder::where('id', $data['production_order_id'])
                ->lockForUpdate()
                ->firstOrFail();

            return ProductionWastage::create([
                'tenant_id' => $order->tenant_id,
                'production_order_id' => $data['production_order_id'],
                'product_id' => $data['product_id'],
                'qty' => $data['qty'],
                'unit_id' => $data['unit_id'],
                'unit_cost' => $data['unit_cost'],
                'reason' => $data['reason'],
                'lot_id' => $data['lot_id'] ?? null,
                'recorded_by' => $data['recorded_by'],
                'recorded_at' => now(),
            ]);
        });
    }
}
