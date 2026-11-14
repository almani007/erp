<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Domain\Enum\WorkOrderStatus;
use Production\Domain\Exception\InsufficientMaterial;
use Production\Infrastructure\Persistence\Eloquent\MaterialIssue;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;

final class IssueMaterials
{
    public function handle(string $orderId, array $materials, string $issuedBy): array
    {
        $order = ProductionOrder::findOrFail($orderId);

        if (! in_array($order->status, [WorkOrderStatus::Released, WorkOrderStatus::InProgress])) {
            throw new \DomainException(
                "Cannot issue materials for work order in status [{$order->status->value}]."
            );
        }

        return DB::transaction(function () use ($order, $materials, $issuedBy): array {
            $issues = [];

            foreach ($materials as $material) {
                // Stock availability is checked by the Inventory module via event/service
                // Here we record the issue and trust the caller has validated stock
                $issues[] = MaterialIssue::create([
                    'production_order_id' => $order->id,
                    'product_id' => $material['product_id'],
                    'qty' => $material['qty'],
                    'unit_id' => $material['unit_id'],
                    'lot_id' => $material['lot_id'] ?? null,
                    'warehouse_id' => $material['warehouse_id'],
                    'issued_at' => now(),
                    'issued_by' => $issuedBy,
                ]);
            }

            if ($order->status === WorkOrderStatus::Released) {
                $order->update(['status' => WorkOrderStatus::InProgress]);
            }

            return $issues;
        });
    }
}
