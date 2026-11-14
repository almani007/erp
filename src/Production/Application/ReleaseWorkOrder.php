<?php

declare(strict_types=1);

namespace Production\Application;

use Production\Domain\Enum\WorkOrderStatus;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;

final class ReleaseWorkOrder
{
    public function handle(string $orderId): ProductionOrder
    {
        $order = ProductionOrder::findOrFail($orderId);

        if (! $order->status->canTransitionTo(WorkOrderStatus::Released)) {
            throw new \DomainException(
                "Cannot release work order in status [{$order->status->value}]."
            );
        }

        $order->update([
            'status' => WorkOrderStatus::Released,
            'actual_start' => now(),
        ]);

        return $order->refresh();
    }
}
