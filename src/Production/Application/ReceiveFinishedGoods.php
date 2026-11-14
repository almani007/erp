<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Domain\Enum\WorkOrderStatus;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;

final class ReceiveFinishedGoods
{
    /**
     * Receive finished goods from a completed production order into inventory.
     * The actual stock receipt is handled by the Inventory module via the
     * ProductionCompleted event or direct service call.
     */
    public function handle(string $orderId): ProductionOrder
    {
        $order = ProductionOrder::findOrFail($orderId);

        if ($order->status !== WorkOrderStatus::Completed) {
            throw new \DomainException(
                "Cannot receive finished goods for work order in status [{$order->status->value}]."
            );
        }

        return DB::transaction(function () use ($order): ProductionOrder {
            $order->update(['status' => WorkOrderStatus::Closed]);

            return $order->refresh();
        });
    }
}
