<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Illuminate\Support\Facades\DB;
use Purchasing\Domain\Enum\RequisitionStatus;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseRequisition;

final readonly class ApproveRequisition
{
    public function execute(string $requisitionId, string $approvedBy, bool $approve = true): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisitionId, $approvedBy, $approve): PurchaseRequisition {
            $requisition = PurchaseRequisition::lockForUpdate()->findOrFail($requisitionId);

            $targetStatus = $approve ? RequisitionStatus::Approved : RequisitionStatus::Rejected;

            if (! $requisition->status->canTransitionTo($targetStatus)) {
                throw new \DomainException(
                    "Cannot transition requisition from {$requisition->status->value} to {$targetStatus->value}."
                );
            }

            $requisition->update([
                'status' => $targetStatus,
                'approved_by' => $approvedBy,
            ]);

            return $requisition;
        });
    }
}
