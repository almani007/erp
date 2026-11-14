<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Domain\Enum\PurchaseOrderStatus;
use Purchasing\Domain\Event\PurchaseOrderApproved;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseOrder;

final readonly class ApprovePurchaseOrder
{
    public function execute(string $purchaseOrderId, string $approvedBy): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrderId, $approvedBy): PurchaseOrder {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrderId);

            if (! $po->status->canTransitionTo(PurchaseOrderStatus::Approved)) {
                throw new \DomainException(
                    "Cannot approve purchase order in status {$po->status->value}."
                );
            }

            $po->update([
                'status' => PurchaseOrderStatus::Approved,
                'approved_by' => $approvedBy,
            ]);

            event(new PurchaseOrderApproved(
                purchaseOrderId: $po->id,
                tenantId: $po->tenant_id,
                companyId: $po->company_id,
                supplierId: $po->supplier_id,
                approvedBy: $approvedBy,
                occurredAt: new \DateTimeImmutable(),
            ));

            return $po;
        });
    }
}
