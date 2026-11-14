<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Domain\Enum\WorkOrderStatus;
use Production\Domain\Event\ProductionCompleted;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;
use Production\Infrastructure\Persistence\Eloquent\ProductionOutput;
use Production\Infrastructure\Persistence\Eloquent\ProductionWastage;

final class RecordOutput
{
    public function handle(string $orderId, array $data, string $recordedBy): ProductionOrder
    {
        $order = ProductionOrder::findOrFail($orderId);

        if ($order->status !== WorkOrderStatus::InProgress) {
            throw new \DomainException(
                "Cannot record output for work order in status [{$order->status->value}]."
            );
        }

        return DB::transaction(function () use ($order, $data, $recordedBy): ProductionOrder {
            if (! empty($data['output_qty'])) {
                ProductionOutput::create([
                    'production_order_id' => $order->id,
                    'product_id' => $data['product_id'],
                    'qty' => $data['output_qty'],
                    'unit_id' => $data['unit_id'],
                    'lot_id' => $data['lot_id'] ?? null,
                    'unit_cost' => $data['unit_cost'] ?? '0.0000',
                    'recorded_at' => now(),
                    'recorded_by' => $recordedBy,
                ]);
            }

            if (! empty($data['wastage_qty'])) {
                ProductionWastage::create([
                    'production_order_id' => $order->id,
                    'product_id' => $data['wastage_product_id'] ?? $data['product_id'],
                    'qty' => $data['wastage_qty'],
                    'reason' => $data['wastage_reason'] ?? null,
                    'recorded_at' => now(),
                ]);
            }

            $order->update([
                'actual_output_qty' => bcadd($order->actual_output_qty, $data['output_qty'] ?? '0', 6),
                'scrap_qty' => bcadd($order->scrap_qty, $data['scrap_qty'] ?? '0', 6),
                'wastage_qty' => bcadd($order->wastage_qty, $data['wastage_qty'] ?? '0', 6),
            ]);

            if (($data['is_final'] ?? false) === true) {
                $order->update([
                    'status' => WorkOrderStatus::Completed,
                    'actual_end' => now(),
                ]);

                event(new ProductionCompleted(
                    productionOrderId: $order->id,
                    productId: $data['product_id'],
                    outputQty: $order->actual_output_qty,
                    scrapQty: $order->scrap_qty,
                    wastageQty: $order->wastage_qty,
                    tenantId: $order->tenant_id,
                ));
            }

            return $order->refresh();
        });
    }
}
